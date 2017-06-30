'use strict';

require('dotenv').config();
var aws = require('aws-sdk');
var binary_case = require('binary-case');
var child_process = require('child_process');

module.exports.handle = (event, context, callback) => {
  var response = '';
  var php = './php';

  // When using 'serverless invoke local' use the system PHP binary instead
  if (typeof process.env.PWD !== "undefined") {
    php = 'php';
  }

  // Build the context object data
  var contextData = {};
  Object.keys(context).forEach(function(key) {
    if (typeof context[key] !== 'function') {
      contextData[key] = context[key];
    }
  });

  var rdsPassword = process.env.PRESSLESS_DB_PASSWORD;
  if (process.env.PRESSLESS_DB_HOST.indexOf('.rds.amazonaws.com') > 0) {
    // Attempt to get RDS auth token
    var rdsToken = new aws.RDS.Signer({
      region: process.env.PRESSLESS_DB_HOST.split('.')[2],
      username: process.env.PRESSLESS_DB_USER || 'pressless-rds', 
      hostname: process.env.PRESSLESS_DB_HOST,
      port: process.env.PRESSLESS_DB_PORT || 3306
    }).getAuthToken();
    if (rdsToken) {
      rdsPassword = rdsToken;      
    } else {
      console.log('Unable to retrieve database token', process.env);
    }
  }
  
  // Launch PHP
  var args = ['handler.php', JSON.stringify(event), JSON.stringify(contextData)];
  var options = {
    stdio: ['pipe', 'pipe', 'pipe', 'pipe'], 
    env: {
      AWS_ACCESS_KEY_ID: process.env.AWS_ACCESS_KEY_ID, 
      AWS_SECRET_ACCESS_KEY: process.env.AWS_SECRET_ACCESS_KEY, 
      AWS_SESSION_TOKEN: process.env.AWS_SESSION_TOKEN,
      PRESSLESS_DOMAIN: process.env.PRESSLESS_DOMAIN,
      PRESSLESS_S3_WEBSITE_BUCKET: process.env.PRESSLESS_S3_WEBSITE_BUCKET,
      PRESSLESS_S3_LOGGING_BUCKET: process.env.PRESSLESS_S3_LOGGING_BUCKET,
      PRESSLESS_DB_HOST: process.env.PRESSLESS_DB_HOST || null,
      PRESSLESS_DB_NAME: process.env.PRESSLESS_DB_NAME || null,
      PRESSLESS_DB_USER: process.env.PRESSLESS_DB_USER || null,
      PRESSLESS_DB_PASSWORD: rdsPassword || null
    }
  };
  var proc = child_process.spawn(php, args, options);

  // Send POST body to STDIN based if specific content-type
  if (event.httpMethod == 'POST' && (event.headers['Content-Type'] == 'text/html' || event.headers['Content-Type'] == 'application/xml' || event.headers['Content-Type'] == 'application/json')) {
    proc.stdin.write(event.body + "\n");
    proc.stdin.end();
  }

  // Request for remaining time from context
  proc.stdio[3].on('data', function (data) {
    var remaining = context.getRemainingTimeInMillis();
    proc.stdio[3].write(`${remaining}\n`);
  });

  // Output
  proc.stdout.on('data', function (data) {
    response += data.toString()
  });

  // Logging
  proc.stderr.on('data', function (data) {
    console.log(`${data}`);
  });

  // PHP script execution end
  proc.on('close', function(code) {
    try {
      //console.log('handler.js: response:', response);

      var result = response == '' ? '{}' : JSON.parse(response);

      // needed to tell ApiGateway to decode base64 encoded binary data
      if (result && result['headers'] && result['headers']['X-Binary'] == 'true') {
        result['isBase64Encoded'] = true;
      }

      if (result && result['body']) {
        // convert http urls to https, since ApiGateway requires http for custom domains
        // and we don't want browser complaining about unsafe scripts
        var re = 'http://';
        result['body'] = result['body'].replace(new RegExp(re, 'g'), 'https://');

        // if we got this far and the response isn't a redirect to the s3 website bucket,
        // that means this isn't a cachable page so convert all s3 bucket website links
        // to pressless domain links in case the Wordpress site domain setting is still 
        // set to the s3 website bucket
        result['body'] = result['body'].replace(new RegExp(process.env.PRESSLESS_S3_WEBSITE_BUCKET, 'g'), process.env.PRESSLESS_DOMAIN);
      }

      // since ApiGateway can only receive one instance of each header and there may be multiple cookies
      // that need to be set, we use the fact that header names are case-insensitive to set a max of 512 
      // cookies using different case variations and loop through all X-Set-Cookie-# headers to assign
      // them to one of the Set-Cookie case variations.
      // @see https://forums.aws.amazon.com/thread.jspa?threadID=205782
      if (result && result['headers']) {
        var cookieIterator = binary_case.iterator('set-cookie');
        Object.keys(result['headers']).forEach(function(key) {
          if (key.indexOf('X-Set-Cookie-') == 0) {
            result['headers'][cookieIterator.next().value] = result['headers'][key];
            delete result['headers'][key];
          }
        });
      }

      //console.log('event:', event);
      //console.log(result);
      callback(null, result);
    } catch (e) {
      console.log(`handler.js: error=${JSON.stringify(e)} code=${code}, response=${response}`);
      callback(null, {
          statusCode: 500,
          body: result['body'] || response,
          headers: {'Content-Type': 'text/html', 'X-Error': JSON.stringify(e), 'X-Exit-Code': code}
      });
    }
  });
};
