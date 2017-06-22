'use strict';

require('dotenv').config();
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
      PRESSLESS_S3_LOGGING_BUCKET: process.env.PRESSLESS_S3_LOGGING_BUCKET
    }
  };
  var proc = child_process.spawn(php, args, options);

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
    if (code !== 0 && code !== null) {
      console.log('handler.js code=' + code + ': response:', response);
      try {
        // sometimes we get a bad exit code but a valid response
        var result = JSON.parse(response);
        if (parseInt(result.statusCode) >= 200 && parseInt(result.statusCode) <= 308) return callback(null, result);  

        return callback(null, {
            statusCode: 500,
            body: result['body'] || response,
            headers: {'Content-Type': 'text/html', 'X-Exit-Code': code}
        });
      } catch (e) {
        return callback(null, {
            statusCode: 500,
            body: response,
            headers: {'Content-Type': 'text/html', 'X-Exit-Code': code, 'X-Error': JSON.stringify(e)}
        });
      }
    }

    try {
      console.log('handler.js: response:', response);

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
      callback(null, {
          statusCode: 500,
          body: response,
          headers: {'Content-Type': 'text/html', 'X-Error': JSON.stringify(e)}
      });
    }
  });
};
