'use strict';

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
  var options = {'stdio': ['pipe', 'pipe', 'pipe', 'pipe']};
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
    if (code !== 0) {
      try {
        var result = JSON.parse(response);
        return callback(null, {
            statusCode: 500,
            body: result['body'] || response,
            headers: {'Content-Type': 'text/html'}
        });
      } catch (e) {
        callback(null, {
            statusCode: 500,
            body: response,
            headers: {'Content-Type': 'text/html', 'X-Error': JSON.stringify(e)}
        });
      }
    }

    try {
      console.log('handler.js: response:', response)

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
