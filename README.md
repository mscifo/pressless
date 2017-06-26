# Pressless
[![serverless][badge-serverless]](http://www.serverless.com)
[![language][badge-php]](http://php.net)
[![language][badge-nodejs]](http://nodejs.org)
[![license][badge-license]](LICENSE)

A tool that migrates an existing Wordpress site into a fully functioning Serverless site, powered by AWS (Cloudfront, API Gateway, Lambda, S3).

Pressless will:
- Create an AWS API Gateway custom domain and assign the specified AWS ACM certificate (via the `domain` command)
- Create a CNAME record in Route53 for the Cloudfront distribution of the new API Gateway custom domain
- Provide you with any other DNS records that need to be created/modified manually
- Copy your existing Wordpress database into a new database (via the `copydb` command)
- Package your existing Wordpress site into an AWS Lambda function and setup an AWS API Gateway Lambda Proxy  (via the `deploy` command)
- Create an AWS S3 website and logging bucket
- Automatically store any Wordpress uploads to your AWS S3 website bucket
- Attempt to automatically cache all non-admin GET requests to S3

# Usage
Install this project:
```
npm install -g pressless
```

## Running Pressless
```
pressless
```
```
                                         ___                              
                                        /\_ \                             
 _____    _ __     __     ____    ____  \//\ \       __     ____    ____  
/\ '__`\ /\`'__\ /'__`\  /',__\  /',__\   \ \ \    /'__`\  /',__\  /',__\ 
\ \ \L\ \\ \ \/ /\  __/ /\__, `\/\__, `\   \_\ \_ /\  __/ /\__, `\/\__, `\
 \ \ ,__/ \ \_\ \ \____\\/\____/\/\____/   /\____\\ \____\\/\____/\/\____/
  \ \ \/   \/_/  \/____/ \/___/  \/___/    \/____/ \/____/ \/___/  \/___/ 
   \ \_\                ---helping wordpress cost less--- 
    \/_/                                                                  


  Usage:  [options] [command]


  Commands:

    setup [options] <domain> <website_bucket>  Setup pressless configuration and install dependencies
    domain                                     Create AWS ApiGateway custom domain
    copydb <dsn>                               Copy database
    deploy [options]                           Deploy Wordpress via Serverless
    test <stage> <request_path>                Test the Serverless function

  Options:

    -h, --help  output usage information
```

# Thanks
* [Andy Raines][git-arains] for the inspiration and base for this project

[badge-serverless]:   http://public.serverless.com/badges/v3.svg
[badge-php]:     https://img.shields.io/badge/language-php-blue.svg
[badge-nodejs]:     https://img.shields.io/badge/language-nodejs-blue.svg
[badge-license]:      https://img.shields.io/badge/license-MIT-orange.svg

[git-repo]:      https://github.com/mscifo/pressless
[git-arains]:      https://github.com/araines/serverless-php