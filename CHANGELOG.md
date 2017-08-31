# 0.9.0

## Features

* Added functionality to invalidate s3 cache when a post/page is saved, along with invalidating any other cached URLs defined in an `.invalidate` file.
* Added functionality to check file size of the `wp-content/uploads` directory and exclude it if it's greater than 200MB unless the `--include-uploads` CLI option is specified.
* Added the `copyuploads` command which syncs the `wp-content/uploads` directory to S3 for use when the directory was excluded due to a large file size.

## Bugs

* Fixed issue that may have prevented root domain s3 bucket from being created.
* Added another check to ensure `load-scripts.php` is buffered properly in order to ensure necessary javascript files are included.
* Added some missing session related functions which may be used by some wordpress plugins.
* Switched from using `nodejs` to `node` binary in shell calls since `nodejs` is legacy and might not always exist.

# 0.8.3

## Bugs

* Fix invalid reference to custom serverless-domain-manager plugin.

## Other

* Added 'powered by' badge.

# 0.8.2

## Bugs

* Ensure that an s3 website bucket for the root domain is created if the specified s3 website bucket has a subdomain (i.e. www).
* Fix potential php error by changing to wordpress directory before loading.
* Added 2 extra IAM permissions in order to auto create Route53 CNAME for pressless domain.
* Set buffer length for mysqldump command to ensure a restore doesn't fail if the server doesn't support a higher buffer limit.
* Revert inclusion of PATH variable when performing npm install since it causes more issues than it prevents.

## Other

* Remove custom serverless-domain-manager.  Changes have been merged upstream.

# 0.8.1

## Bugs

* Make sure we account for different variations of wordpress DB_* constants in wp-config.php.
* Avoid possible issue with installing spawn-sync dependency https://github.com/ForbesLindesay/spawn-sync/issues/42.
* Avoid possible "permission denied" error when executing serverless standalone by executing it through nodejs instead.

## Other

* Bumped Wordpress Jetpack plugin install to version 5.1.

# 0.8.0

## Features

* Added warm command to pre-warm s3 website bucket cache.
* Added ability to just deploy the lambda function without using cloudformation.
* Added logs command which wraps serverless logs in order to watch the lambda logs from the cli.

# 0.7.3

## Features

* Set default expiration for all written s3 objects.
* Make sure we set an appropriate CORS policy for the s3 website bucket.
* Support for standard wordpress search and for modifying form actions to use the proper domain.
* Support for specifying custom database credentials during setup command which are populated/retrieved via environment variables, and for RDS IAM Authentication so passwords don't have to be specified/included.

## Bugs

* Remove debugging output when calling overridden file related functions.
* Switch https -> http for website bucket url's in cached buffer, since website bucket doesn't support https.
* Cleanup processing of raw post data and ensure requests to /xmlrpc.php always assume raw post data is passed.
* Move cacheability detection before populating $_SERVER since we now use it to determine the proper host and port.
* Hardcode the wordpress home/site url's to the pressless domain so it doesn't have to be changed in the database.
* Override session functions since pressless doesn't include the session extension and some plugins may call them.
* Disable the wordpress plugin and theme editor/installer.
* Make sure we return an error page if an E_ERROR is the last occurring error, even if we've already started to render.
* Added xmlrpc extention to php build, since it may be needed by jetpack.
* Make sure we remove any login captcha plugins, since they won't work via the pressless domain.
* Inject some custom wordpress filters via custom wp-content/db.php file in order to ensure the proper home and site urls, as well as to ensure that canonical redirect is disabled in order to avoid a redirect loop.

# 0.7.2

## Bugs

* Fixed some bad S3 SDK calls which were still using v3 instead of v2.
* Removed code that handled non-zero exit codes from php process separately, now handled collectively.
* Rewrite URL's as needed when dealing with uncacheable content.
* Pass the S3 website bucket domain instead of the actual API Gateway domain to PHP in order to avoid canonical redirects by Wordpress.

## Other

* Updated readme to include two other required permissions for the recommended least privilege IAM policy.

# 0.7.1

## Other

* Updated readme to include one other required permission for the recommended least privilege IAM policy.

# 0.7.0

## Features

* Support for specifying custom database credentials during setup command which are populated/retrieved via environment variables, and for RDS IAM Authentication so passwords don't have to be specified/included.

# 0.6.4

## Features

* Switch to using a prefix for service name and deployment bucket so we can establish a least privilege IAM policy with permissions needed for pressless to operate.

## Bugs

* Fix for evolution support when using the copydb command and some other minor improvements.

# 0.6.3

## Bugs

* Fix for new database config not being properly written to wp-config.php.migration.

## Other

* Updated readme.
* Updated changelog.

# 0.6.2

## Features

* Refactored copydb command to remove dependency on mysql-tools package.

# 0.6.1

## Bugs

* Fixed some issues with the copydb command.

# 0.6.0

## Features

* Added serverless-apigw-binary plugin to setup binary media types.

# 0.5.5

## Features

* Renamed custom s3 deployment bucket from ${website_bucket}-sls-cfm to ${website_bucket}-deploys.

# 0.5.4

## Features

* Added support for checking dns for alternative website bucket domain (with or without www) and ouputing appropriate messaging.

# 0.5.3

## Bugs

* Fix reference to 'ServerlessDeploymentBucket', which is no longer used now that we specify a custom deployment bucket.

# 0.5.2

## Features

* Switch to using a custom s3 bucket for serverless deployment data.

## Bugs

* Make sure we create the custom s3 deployment bucket since serverless doesn't auto-create custom buckets.
* Switch to using a default stage/env of '0', since '_' is not a valid character for a serverless stack service name.  Also do proper validation on stage/env input.

# 0.5.1

## Features

* Switch to using ServerlessCustomDomain class for creating ApiGateway domain instead of calling serverless executable.
* Switch to a default stage/env of '_', since different stages/envs should be handled by specifying different domain names.

## Bugs

* Added missing logger when instantiating ServerlessCustomDomain.

# 0.5.0

## Features

* Autodetect status of required dns records and output appropriate messaging.
* Updated to latest version of serverless-domain-manager with some customizations.
* Check to see if ApiGateway domain already exists before creating.  Also perform creation as part of setup process.
* Output needed DNS records after deployment.

# 0.4.1

## Features

* Switch to using a runtime calculated variable for the stage option used in serverless-domain-manager.

# 0.4.0

## Features

* Removed tls command in favor of requesting a new AWS ACM certificate during setup.

## Other

* Removed todos in header.
* Updated description of domain command.

# 0.3.0

## Features

* Removed the env, region and verbose cli options for the setup command.  They are now specific to the deploy command, allowing different regions/environments to be deployed to regardless of what was specified during setup.
* Added domain validation during setup via tldjs.

# 0.2.0

## Features

* Check for missing composer dependencies and install/run composer.
* Switched to v2 of the AWS SDK, since v3 requires php >= 5.5.
* Allow for specifying php version to use from cli argument.  Defaults to php-5.4.45, since some older plugins use the mysql_ functions which were deprecated in php-5.5.
* Switched to populating pressless vars into a .env file which then gets injected into the php environment via the nodejs handler.
* Added functionality to cache output buffer to s3 and redirect to static site domain. Intended to be coupled with an s3 website error document redirect.
* Override common file functions in order to replace local paths with s3 paths since lambda filesystem is readonly.    
* Added zip extension to allowed list for serving static files.
* Refactored how we handle output.
* Removed inclusion of WP Static HTML Output plugin and replaced with Jetpack plugin.
* Improved support for Evolution.
* Added new php binary with simplexml and dom extensions.
* Added ability to specify ACM certificate name as cli option to be used by servless-domain-manager instead of trying to lookup the ACM certificate and match by domain name.
    
## Bugs

* Ensure we are setting cookies received from ApiGateway.
* Fix for ensuring we can send multiple cookie headers to ApiGateway.
* Ensure static xml files aren't converted to base64.
* Ensure that numeric values in / are converted to an int type.
* Detect if post body is base64-encoded, and decode if it is.
* Detect if a raw POST body is present and handle accoridingly.
* Ensure that multiple query string parameters of the same name with array notation are concatenated.
* Populated extra global vars that are needed by Wordpress.
* Added some missing extensions required by Wordpress.
* Some random wordpress issue fixes.
* Fixed issue that would cause 'serverless deploy' to fail if the service collective length of the provided domain/env/region was too long.

## Other

* Updated readme.

# 0.1.0

Initial release.