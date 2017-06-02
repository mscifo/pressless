
'use strict';

const AWS = require('aws-sdk');

class ServerlessCustomDomain {

    constructor(serverless) {
        this.serverless = serverless;

        // The domain name specified in the serverless file
        this.givenDomainName = this.serverless.service.custom.customDomain.domainName;
        this.apigateway = new AWS.APIGateway({
            region: this.serverless.service.provider.region,
        });

        this.commands = {
            create_domain: {
                usage: 'Creates a domain using the domain name defined in the serverless file',
                lifecycleEvents: ['create', ],
            },
            delete_domain: {
                usage: 'Deletes a domain using the domain name defined in the serverless file',
                lifecycleEvents: ['delete', ],
            },
        };

        this.hooks = {
            'delete_domain:delete': this.deleteDomain.bind(this),
            'create_domain:create': this.createDomain.bind(this),
            'before:deploy:deploy': this.setUpBasePathMapping.bind(this),
        };
    }

    createDomain() {
        const createDomainName = this.getCertArn().then(data => this.createDomainName(data));
        return Promise.all([createDomainName])
            .then(() => (this.serverless.cli.log('Domain was created, may take up to 40 mins to be initialized.')))
            .
        catch ((err) => {
            throw new Error(`${err}
            ${this.givenDomainName}
            was not created.`);
        });
    }

    deleteDomain() {
        return this.getDomain().then((data) => {
            const promises = [
            this.clearDomainName(), ];

            return (Promise.all(promises).then(() => (this.serverless.cli.log('Domain was deleted.'))));
        }).
        catch ((err) => {
            throw new Error(`${err}
            ${this.givenDomainName}
            was not deleted.`);
        });
    }

    setUpBasePathMapping() {
        return this.getDomain().then(() => {
            const deploymentId = this.getDeploymentId();
            this.addResources(deploymentId);
        }).
        catch ((err) => {
            throw new Error(`${err}
            Try running sls create_domain first.`);
        });
    }

    /**
     * Gets the deployment id
     */
    getDeploymentId() {
        // Searches for the deployment id from the cloud formation template
        const cloudTemplate = this.serverless.service.provider.compiledCloudFormationTemplate;

        const deploymentId = Object.keys(cloudTemplate.Resources).find((key) => {
            const resource = cloudTemplate.Resources[key];
            return resource.Type === 'AWS::ApiGateway::Deployment';
        });

        if (!deploymentId) {
            throw new Error('Cannot find AWS::ApiGateway::Deployment');
        }
        return deploymentId;
    }

    /**
     *  Adds the custom domain, stage, and basepath to the resource section
     *  @param  deployId    Used to set the timing for creating the basepath
     */
    addResources(deployId) {
        const service = this.serverless.service;

        if (!service.custom.customDomain) {
            throw new Error('customDomain settings in Serverless are not configured correctly');
        }

        let basePath = service.custom.customDomain.basePath;

        // Base path cannot be empty, instead it must be (none)
        if (!basePath || basePath.length < 1 || basePath.trim() === '') {
            basePath = '(none)';
        }

        // Creates the pathmapping
        const pathmapping = {
            Type: 'AWS::ApiGateway::BasePathMapping',
            DependsOn: deployId,
            Properties: {
                BasePath: basePath,
                DomainName: this.givenDomainName,
                RestApiId: {
                    Ref: 'ApiGatewayRestApi',
                },
                Stage: service.custom.customDomain.stage,
            },
        };

        // Verify the cloudFormationTemplate exists
        if (!service.provider.compiledCloudFormationTemplate) {
            this.serverless.service.provider.compiledCloudFormationTemplate = {};
        }

        if (!service.provider.compiledCloudFormationTemplate.Resources) {
            service.provider.compiledCloudFormationTemplate.Resources = {};
        }

        // Creates and sets the resources
        service.provider.compiledCloudFormationTemplate.Resources.pathmapping = pathmapping;
    }

    /*
     * Obtains the certification arn
     */
    getCertArn() {
        const acm = new AWS.ACM({
            region: 'us-east-1',
        }); // us-east-1 is the only region that can be accepted (3/21)

        const certArn = acm.listCertificates().promise();
        return certArn.then((data) => {
            // The more specific name will be the longest
            let nameLength = 0;
            // The arn of the choosen certificate
            let certificateArn;
            // The certificate name
            let certificateName = this.serverless.service.custom.customDomain.certificateName;


            // Checks if a certificate name is given
            if (certificateName != null) {
                const foundCertificate = data.CertificateSummaryList.find(certificate => (certificate.DomainName === certificateName));

                if (foundCertificate != null) {
                    certificateArn = foundCertificate.CertificateArn;
                }
            } else {
                certificateName = this.givenDomainName;
                data.CertificateSummaryList.forEach((certificate) => {
                    let certificateListName = certificate.DomainName;

                    // Looks for wild card and takes it out when checking
                    if (certificateListName[0] === '*') {
                        certificateListName = certificateListName.substr(1);
                    }

                    // Looks to see if the name in the list is within the given domain
                    // Also checks if the name is more specific than previous ones
                    if (certificateName.includes(certificateListName) && certificateListName.length > nameLength) {
                        nameLength = certificateListName.length;
                        certificateArn = certificate.CertificateArn;
                    }
                });
            }

            if (certificateArn == null) {
                throw Error(`Could not find the certificate ${certificateName}`);
            }
            return certificateArn;
        });
    }

    /**
     *  Creates the domain name through the api gateway
     *  @param certificateArn   The certificate needed to create the new domain
     */
    createDomainName(givenCertificateArn) {
        const createDomainNameParams = {
            domainName: this.givenDomainName,
            certificateArn: givenCertificateArn,
        };

        // This will return the distributionDomainName
        const createDomain = this.apigateway.createDomainName(createDomainNameParams).promise();
        return createDomain.then(data => data.distributionDomainName);
    }

    /**
     * Deletes the domain names specified in the serverless file
     */
    clearDomainName() {
        return this.apigateway.deleteDomainName({
            domainName: this.givenDomainName,
        }).promise();
    }

    /*
     * Get information on domain
     */
    getDomain() {
        const getDomainNameParams = {
            domainName: this.givenDomainName,
        };

        const getDomainPromise = this.apigateway.getDomainName(getDomainNameParams).promise();
        return getDomainPromise.then(data => (data), () => {
            throw new Error(`Cannot find specified domain name ${this.givenDomainName}.`);
        });
    }
}

module.exports = ServerlessCustomDomain; 