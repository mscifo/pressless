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

# Requirements

Pressless requires `nodejs-6.x` or higher to run.

Pressless also requires AWS API credentials that have the following policy grant (least privilege):
```
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Effect": "Allow",
            "Action": [
                "acm:ListCertificates",
                "cloudformation:CreateStack",
                "cloudformation:DescribeStacks",
                "cloudformation:DescribeStackEvents",
                "cloudformation:DescribeStackResources",
                "cloudformation:ValidateTemplate",
                "cloudfront:GetDistribution",
                "cloudfront:UpdateDistribution",
                "logs:DescribeLogGroups",
                "logs:CreateLogGroup",
                "route53:ListHostedZones",
                "route53:ChangeResourceRecordSets",
                "s3:CreateBucket"
            ],
            "Resource": "*"
        },
        {
            "Effect": "Allow",
            "Action": "iam:*",
            "Resource": "arn:aws:iam::*:role/pl-*"
        },
        {
            "Effect": "Allow",
            "Action": [
                "apigateway:GET",
                "apigateway:POST",
                "apigateway:PUT",
                "apigateway:DELETE"
            ],
            "Resource": [
                "arn:aws:apigateway:*::/domainnames",
                "arn:aws:apigateway:*::/domainnames/*",
                "arn:aws:apigateway:*::/domainnames/*/*",
                "arn:aws:apigateway:*::/restapis",
                "arn:aws:apigateway:*::/restapis/*",
                "arn:aws:apigateway:*::/restapis/*/*"
            ]
        },
        {
            "Effect": "Allow",
            "Action": [
                "lambda:Get*",
                "lambda:List*",
                "lambda:CreateFunction"
            ],
            "Resource": "*"
        },
        {
            "Effect": "Allow",
            "Action": [
                "lambda:AddPermission",
                "lambda:CreateAlias",
                "lambda:DeleteFunction",
                "lambda:InvokeFunction",
                "lambda:PublishVersion",
                "lambda:RemovePermission",
                "lambda:Update*"
            ],
            "Resource": "arn:aws:lambda:*:*:function:pl-*"
        },
        {
            "Effect": "Allow",
            "Action": [
                "s3:GetBucketLocation",
                "s3:ListBucket",
                "s3:GetObject",
                "s3:PutObject"
            ],
            "Resource": "arn:aws:s3:::pressless-deploys-*"
        },
        {
            "Effect": "Allow",
            "Action": [
                "cloudformation:UpdateStack",
                "cloudformation:DeleteStack"
            ],
            "Resource": "arn:aws:cloudformation:*:*:stack/pl-*"
        }
    ]
}
```

If you wish to utilize [RDS IAM Authentication](http://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/UsingWithRDS.IAMDBAuth.html) so that you don't have to hard code your database password into `wp-config.php`, you will also need to create an IAM authenticatable user on the RDS instance (`CREATE USER [USERNAME] IDENTIFIED WITH AWSAuthenticationPlugin as 'RDS';`) along with the necessary grants.  Then just specify an SSL connection to MySQL using the newly created user when you run `pressless setup`.  For example:
```
pressless setup -c [AWS_ACM_CERTIFICATE] -d 'mysql+ssl://[USERNAME]@[RDS_HOST]/[DATABASE]' [DOMAIN] [S3_WEBSITE_BUCKET]
```

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
    exportdb <db_username>                     Export the Pressless database
    deploy [options]                           Deploy Wordpress via Serverless
    copyuploads [options]                      Copy "wp-content/uploads" to S3
    logs [options]                             Watch lambda logs
    test <stage> <request_path>                Test the Serverless function
    warm                                       Warm the S3 website bucket cache by crawling all pages
    invalidate                                 Invalidate the S3 website bucket cache (except `wp-content/uploads`)

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
