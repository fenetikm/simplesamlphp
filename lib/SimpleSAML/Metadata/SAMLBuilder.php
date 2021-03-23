<?php

declare(strict_types=1);

namespace SimpleSAML\Metadata;

use DOMElement;
use SimpleSAML\Assert\Assert;
use SimpleSAML\Configuration;
use SimpleSAML\Logger;
use SimpleSAML\Module\adfs\SAML2\XML\fed\SecurityTokenServiceType;
use SimpleSAML\SAML2\Constants;
//use SimpleSAML\SAML2\XML\md\AbstractEndpointType;
use SimpleSAML\SAML2\XML\md\AbstractIndexedEndpointType;
use SimpleSAML\SAML2\XML\md\AbstractRoleDescriptor;
use SimpleSAML\SAML2\XML\md\AssertionConsumerService;
use SimpleSAML\SAML2\XML\md\AttributeAuthorityDescriptor;
use SimpleSAML\SAML2\XML\md\AttributeConsumingService;
use SimpleSAML\SAML2\XML\md\ContactPerson;
use SimpleSAML\SAML2\XML\md\EntityDescriptor;
use SimpleSAML\SAML2\XML\md\IDPSSODescriptor;
use SimpleSAML\SAML2\XML\md\Organization;
use SimpleSAML\SAML2\XML\md\RequestedAttribute;
use SimpleSAML\SAML2\XML\md\SingleLogoutService;
use SimpleSAML\SAML2\XML\md\SingleSignOnService;
use SimpleSAML\SAML2\XML\md\SPSSODescriptor;
use SimpleSAML\SAML2\XML\mdattr\EntityAttributes;
use SimpleSAML\SAML2\XML\mdrpi\RegistrationInfo;
use SimpleSAML\SAML2\XML\mdui\DiscoHints;
use SimpleSAML\SAML2\XML\mdui\Keywords;
use SimpleSAML\SAML2\XML\mdui\Logo;
use SimpleSAML\SAML2\XML\mdui\UIInfo;
use SimpleSAML\SAML2\XML\saml\Attribute;
use SimpleSAML\SAML2\XML\saml\AttributeValue;
use SimpleSAML\SAML2\XML\shibmd\Scope;
use SimpleSAML\SAML2\Utils as SAMLUtils;
use SimpleSAML\Utils;
use SimpleSAML\XML\Utils as XMLUtils;

/**
 * Class for generating SAML 2.0 metadata from SimpleSAMLphp metadata arrays.
 *
 * This class builds SAML 2.0 metadata for an entity by examining the metadata for the entity.
 *
 * @package SimpleSAMLphp
 */

class SAMLBuilder
{
    /**
     * The EntityDescriptor we are building.
     *
     * @var \SimpleSAML\SAML2\XML\md\EntityDescriptor
     */
//    private EntityDescriptor $entityDescriptor;

    /**
     * The entityId for the EntityDescriptor we're building
     *
     * @var string
     */
    private string $entityId;


    /**
     * The maximum time in seconds the metadata should be cached.
     *
     * @var int|null
     */
    private ?int $maxCache = null;


    /**
     * The maximum time in seconds since the current time that this metadata should be considered valid.
     *
     * @var int|null
     */
    private ?int $maxDuration = null;


    /**
     * Initialize the SAML builder.
     *
     * @param string   $entityId The entity id of the entity.
     * @param int|null $maxCache The maximum time in seconds the metadata should be cached. Defaults to null
     * @param int|null $maxDuration The maximum time in seconds this metadata should be considered valid. Defaults
     * to null.
     */
    public function __construct(string $entityId, int $maxCache = null, int $maxDuration = null)
    {
        $this->maxCache = $maxCache;
        $this->maxDuration = $maxDuration;

        $this->entityId = $entityId;

//        $this->entityDescriptor = new EntityDescriptor();
//        $this->entityDescriptor->setEntityID($entityId);
    }


    /**
     * @param array $metadata
    private function setExpiration(array $metadata): void
    {
        if (array_key_exists('expire', $metadata)) {
            if ($metadata['expire'] - time() < $this->maxDuration) {
                $this->maxDuration = $metadata['expire'] - time();
            }
        }

        if ($this->maxCache !== null) {
            $this->entityDescriptor->setCacheDuration('PT' . $this->maxCache . 'S');
        }
        if ($this->maxDuration !== null) {
            $this->entityDescriptor->setValidUntil(time() + $this->maxDuration);
        }
    }
     */


    /**
     * Retrieve the EntityDescriptor element which is generated for this entity.
     *
     * @return \DOMElement The EntityDescriptor element of this entity.
     */
    public function getEntityDescriptor(): DOMElement
    {
        $xml = $this->entityDescriptor->toXML();
        $xml->ownerDocument->appendChild($xml);

        return $xml;
    }


    /**
     * Retrieve the EntityDescriptor as text.
     *
     * This function serializes this EntityDescriptor, and returns it as text.
     *
     * @param bool $formatted Whether the returned EntityDescriptor should be formatted first.
     *
     * @return string The serialized EntityDescriptor.
     */
    public function getEntityDescriptorText(bool $formatted = true): string
    {
        $xml = $this->getEntityDescriptor();
        if ($formatted) {
            $xmlUtils = new Utils\XML();
            $xmlUtils->formatDOMElement($xml);
        }

        return $xml->ownerDocument->saveXML();
    }


    /**
     * Add a SecurityTokenServiceType for ADFS metadata.
     *
     * @param array $metadata The metadata with the information about the SecurityTokenServiceType.
     */
    public function addSecurityTokenServiceType(array $metadata): void
    {
        Assert::notNull($metadata['entityid']);
        Assert::notNull($metadata['metadata-set']);

        $metadata = Configuration::loadFromArray($metadata, $metadata['entityid']);
        $defaultEndpoint = $metadata->getDefaultEndpoint('SingleSignOnService');

        $e = new SecurityTokenServiceType();
        $e->setLocation($defaultEndpoint['Location']);

        $this->addCertificate($e, $metadata);

        $this->entityDescriptor->addRoleDescriptor($e);
    }


    /**
     * Add extensions to the metadata.
     *
     * @param \SimpleSAML\Configuration    $metadata The metadata to get extensions from.
     * @param \SimpleSAML\SAML2\XML\md\AbstractRoleDescriptor $e
     *   Reference to the element where the Extensions element should be included.
     */
    private function addExtensions(Configuration $metadata, AbstractRoleDescriptor $e): void
    {
        if ($metadata->hasValue('hint.cidr')) {
            $a = new Attribute();
            $a->setName('hint.cidr');
            foreach ($metadata->getArray('hint.cidr') as $hint) {
                $a->addAttributeValue(new AttributeValue($hint));
            }
            $e->setExtensions(array_merge($e->getExtensions(), [$a]));
        }

        if ($metadata->hasValue('scope')) {
            foreach ($metadata->getArray('scope') as $scopetext) {
                // Check whether $ ^ ( ) * | \ are in a scope -> assume regex.
                $regex = (1 === preg_match('/[\$\^\)\(\*\|\\\\]/', $scopetext));

                $s = new Scope($scopetext, $regex);
                $e->setExtensions(array_merge($e->getExtensions(), [$s]));
            }
        }

        if ($metadata->hasValue('EntityAttributes')) {
            $ea = new EntityAttributes();
            foreach ($metadata->getArray('EntityAttributes') as $attributeName => $attributeValues) {
                $a = new Attribute();
                $a->setName($attributeName);
                $a->setNameFormat(Constants::NAMEFORMAT_UNSPECIFIED);

                // Attribute names that is not URI is prefixed as this: '{nameformat}name'
                if (preg_match('/^\{(.*?)\}(.*)$/', $attributeName, $matches)) {
                    $a->setName($matches[2]);
                    $nameFormat = $matches[1];
                    if ($nameFormat !== Constants::NAMEFORMAT_UNSPECIFIED) {
                        $a->setNameFormat($nameFormat);
                    }
                }
                foreach ($attributeValues as $attributeValue) {
                    $a->addAttributeValue(new AttributeValue($attributeValue));
                }
                $ea->addChildren($a);
            }
            $this->entityDescriptor->setExtensions(
                array_merge($this->entityDescriptor->getExtensions(), [$ea])
            );
        }

        if ($metadata->hasValue('saml:Extensions')) {
            $this->entityDescriptor->setExtensions(
                array_merge($this->entityDescriptor->getExtensions(), $metadata->getArray('saml:Extensions'))
            );
        }

        if ($metadata->hasValue('RegistrationInfo')) {
/*
          $ri = new RegistrationInfo();
            foreach ($metadata->getArray('RegistrationInfo') as $riName => $riValues) {
                switch ($riName) {
                    case 'authority':
                        $ri->setRegistrationAuthority($riValues);
                        break;
                    case 'instant':
                        $ri->setRegistrationInstant(XMLUtils::xsDateTimeToTimestamp($riValues));
                        break;
                    case 'policies':
                        $ri->setRegistrationPolicy($riValues);
                        break;
                }
            }
            $this->entityDescriptor->setExtensions(
                array_merge($this->entityDescriptor->getExtensions(), [$ri])
            );
*/
            $ri = RegistrationInfo::fromArray($metadata->getArray('RegistrationInfo'));
            $e->setExtensions(array_merge($e->getExtensions(), [$ri]));
        }

        if ($metadata->hasValue('UIInfo')) {
/*
            $ui = new UIInfo();
            foreach ($metadata->getArray('UIInfo') as $uiName => $uiValues) {
                switch ($uiName) {
                    case 'DisplayName':
                        $ui->setDisplayName($uiValues);
                        break;
                    case 'Description':
                        $ui->setDescription($uiValues);
                        break;
                    case 'InformationURL':
                        $ui->setInformationURL($uiValues);
                        break;
                    case 'PrivacyStatementURL':
                        $ui->setPrivacyStatementURL($uiValues);
                        break;
                    case 'Keywords':
                        foreach ($uiValues as $lang => $keywords) {
                            $uiItem = new Keywords($lang, $keywords);
                            $ui->addKeyword($uiItem);
                        }
                        break;
                    case 'Logo':
                        foreach ($uiValues as $logo) {
                            $uiItem = new Logo($logo['url'], $logo['height'], $logo['width'], $logo['lang'] ?? null);
                            $ui->addLogo($uiItem);
                        }
                        break;
                }
            }
*/
            $ui = UIInfo::fromArray($metadata->getArray('UIInfo'));
            $e->setExtensions(array_merge($e->getExtensions(), [$ui]));
        }

        if ($metadata->hasValue('DiscoHints')) {
/*
            $IPHints = $DomainHints = $GeolocationHints = [];
            foreach ($metadata->getArray('DiscoHints') as $dhName => $dhValues) {
                switch ($dhName) {
                    case 'IPHint':
                        $IPHints = $dhValues;
                        break;
                    case 'DomainHint':
                        $DomainHints = $dhValues;
                        break;
                    case 'GeolocationHint':
                        $GeolocationHints = $dhValues;
                        break;
                }
            }
            $dh = new DiscoHints([], $IPHints, $DomainHints, $GeolocationHints);
*/
            $dh = DiscoHints::fromArray($metadata->getArray('DiscoHints'));
            $e->setExtensions(array_merge($e->getExtensions(), [$dh]));
        }
    }


    /**
     * Add an Organization element based on data passed as parameters
     *
     * @param array $orgName An array with the localized OrganizationName.
     * @param array $orgDisplayName An array with the localized OrganizationDisplayName.
     * @param array $orgURL An array with the localized OrganizationURL.
     */
    public function addOrganization(array $orgName, array $orgDisplayName, array $orgURL): void
    {
        $org = new Organization($orgName, $orgDisplayName, $orgURL);

        $this->entityDescriptor->setOrganization($org);
    }


    /**
     * Add an Organization element based on metadata array.
     *
     * @param array $metadata The metadata we should extract the organization information from.
     */
    public function addOrganizationInfo(array $metadata): void
    {
        if (
            empty($metadata['OrganizationName']) ||
            empty($metadata['OrganizationDisplayName']) ||
            empty($metadata['OrganizationURL'])
        ) {
            // empty or incomplete organization information
            return;
        }

        $arrayUtils = new Utils\Arrays();

        $orgName = $arrayUtils->arrayize($metadata['OrganizationName'], 'en');
        $orgDisplayName = $arrayUtils->arrayize($metadata['OrganizationDisplayName'], 'en');
        $orgURL = $arrayUtils->arrayize($metadata['OrganizationURL'], 'en');

        $this->addOrganization($orgName, $orgDisplayName, $orgURL);
    }


    /**
     * Add a list of endpoints to metadata.
     *
     * @param array $endpoints The endpoints.
     * @param bool  $indexed Whether the endpoints should be indexed.
     *
     * @return array An array of endpoint objects,
     *     either \SimpleSAML\SAML2\XML\md\AbstractEndpointType or \SimpleSAML\SAML2\XML\md\AbstractIndexedEndpointType.
     */
//    private static function createEndpoints(array $endpoints, bool $indexed): array
    private static function createEndpoints(array $endpoints, string $type): array
    {
        $ret = [];
        foreach ($endpoints as &$ep) {
            if ($type instanceof AbstractIndexedEndpointType) {
//            if ($indexed) {
//                $t = new IndexedEndpointType();
                if (!isset($ep['index'])) {
                    // Find the maximum index
                    $maxIndex = -1;
                    foreach ($endpoints as $ep) {
                        if (!isset($ep['index'])) {
                            continue;
                        }

                        if ($ep['index'] > $maxIndex) {
                            $maxIndex = $ep['index'];
                        }
                    }

                    $ep['index'] = $maxIndex + 1;
                }

                $attributes = [];
                if (isset($ep['hoksso:ProtocolBinding'])) {
                    $doc = DOMDocumentFactory::create();
                    $elt = $doc->createElement("placeholder");
                    $elt->setAttributeNS(
                        Constants::NS_HOK,
                        'hoksso:ProtocolBinding',
                        Constants::BINDING_HTTP_REDIRECT
                    );

                    $attributes[] = $elt->getAttributeNode('hoksso:ProtocolBinding');
                }

                $t = new $type($ep['index'], $ep['Binding'], $ep['Location'], null, isset($ep['ResponseLocation']) ? $ep['ResponseLocation'] : null, isset($ep['hoksso:ProtocolBinding']) ? $attributes : []);

//                $t->setIndex($ep['index']);
            } else {
//                $t = new EndpointType();
                $attributes = [];
                if (isset($ep['hoksso:ProtocolBinding'])) {
                    $doc = DOMDocumentFactory::create();
                    $elt = $doc->createElement("placeholder");
                    $elt->setAttributeNS(
                        Constants::NS_HOK,
                        'hoksso:ProtocolBinding',
                        Constants::BINDING_HTTP_REDIRECT
                    );

                    $attributes[] = $elt->getAttributeNode('hoksso:ProtocolBinding');
                }

                $t = new $type($ep['Binding'], $ep['Location'], isset($ep['ResponseLocation']) ? $ep['ResponseLocation'] : null, isset($ep['hoksso:ProtocolBinding']) ? $attributes : []);
            }
/*
            $t->setBinding($ep['Binding']);
            $t->setLocation($ep['Location']);
            if (isset($ep['ResponseLocation'])) {
                $t->setResponseLocation($ep['ResponseLocation']);
            }
            if (isset($ep['hoksso:ProtocolBinding'])) {
                $t->setAttributeNS(
                    Constants::NS_HOK,
                    'hoksso:ProtocolBinding',
                    Constants::BINDING_HTTP_REDIRECT
                );
            }
*/

            $ret[] = $t;
        }

        return $ret;
    }


    /**
     * Add an AttributeConsumingService element to the metadata.
     *
     * @param \SimpleSAML\SAML2\XML\md\SPSSODescriptor $spDesc The SPSSODescriptor element.
     * @param \SimpleSAML\Configuration     $metadata The metadata.
     */
    private function addAttributeConsumingService(
        SPSSODescriptor $spDesc,
        Configuration $metadata
    ): void {
        $attributes = $metadata->getOptionalArray('attributes', []);
        $name = $metadata->getOptionalLocalizedString('name', null);

        if ($names === null || count($attributes) == 0) {
            // we cannot add an AttributeConsumingService without name and attributes
            return;
        }

        $attributesrequired = $metadata->getOptionalArray('attributes.required', []);

        /*
         * Add an AttributeConsumingService element with information as name and description and list
         * of requested attributes
         */
        $attributeconsumer = new AttributeConsumingService();
        $attributeconsumer->setIndex($metadata->getOptionalInteger('attributes.index', 0));

        if ($metadata->hasValue('attributes.isDefault')) {
            $attributeconsumer->setIsDefault($metadata->getOptionalBoolean('attributes.isDefault', false));
        }

        $attributeconsumer->setServiceName($name);
        $attributeconsumer->setServiceDescription($metadata->getOptionalLocalizedString('description', []));

        $nameFormat = $metadata->getOptionalString('attributes.NameFormat', Constants::NAMEFORMAT_URI);
        foreach ($attributes as $friendlyName => $attribute) {
            $t = new RequestedAttribute(
                $attribute,
                in_array($attribute, $attributesrequired, true),
                $nameFormat,
                is_int($friendlyName) ? null : $friendlyName
            );

            $requestedAttributes[] = $t;
        }

        $attributeconsumer = new AttributeConsumingService(
            $metadata->getInteger('attributes.index', 0),
            $serviceName,
            $requestedAttributes,
            $isDefault,
            $serviceDescription
        );

        $spDesc->addAttributeConsumingService($attributeconsumer);
    }


    /**
     * Add a specific type of metadata to an entity.
     *
     * @param string $set The metadata set this metadata comes from.
     * @param array  $metadata The metadata.
     */
    public function addMetadata(string $set, array $metadata): void
    {
//        $this->setExpiration($metadata);

        switch ($set) {
            case 'saml20-sp-remote':
                $this->addMetadataSP20($metadata);
                break;
            case 'saml20-idp-remote':
                $this->addMetadataIdP20($metadata);
                break;
            case 'attributeauthority-remote':
                $this->addAttributeAuthority($metadata);
                break;
            default:
                Logger::warning('Unable to generate metadata for unknown type \'' . $set . '\'.');
        }
    }


    /**
     * Add SAML 2.0 SP metadata.
     *
     * @param array $metadata The metadata.
     * @param string[] $protocols The protocols supported. Defaults to \SAML2\Constants::NS_SAMLP.
     */
    public function addMetadataSP20(array $metadata, array $protocols = [Constants::NS_SAMLP]): void
    {
        Assert::notNull($metadata['entityid']);
        Assert::notNull($metadata['metadata-set']);

        $metadata = Configuration::loadFromArray($metadata, $metadata['entityid']);

        $e = new SPSSODescriptor();
        $e->setProtocolSupportEnumeration($protocols);

        if ($metadata->hasValue('saml20.sign.assertion')) {
            $e->setWantAssertionsSigned($metadata->getBoolean('saml20.sign.assertion'));
        }

        if ($metadata->hasValue('redirect.validate')) {
            $e->setAuthnRequestsSigned($metadata->getBoolean('redirect.validate'));
        } elseif ($metadata->hasValue('validate.authnrequest')) {
            $e->setAuthnRequestsSigned($metadata->getBoolean('validate.authnrequest'));
        }

        $this->addExtensions($metadata, $e);

        $this->addCertificate($e, $metadata);

        $e->setSingleLogoutService(self::createEndpoints($metadata->getEndpoints('SingleLogoutService'), SingleLogoutService::class));

        $e->setNameIDFormat($metadata->getOptionalArrayizeString('NameIDFormat', []));

        $endpoints = $metadata->getEndpoints('AssertionConsumerService');
        foreach ($metadata->getOptionalArrayizeString('AssertionConsumerService.artifact', []) as $acs) {
            $endpoints[] = [
                'Binding'  => Constants::BINDING_HTTP_ARTIFACT,
                'Location' => $acs,
            ];
        }
        $e->setAssertionConsumerService(self::createEndpoints($endpoints, AssertionConsumerService::class));

        $this->addAttributeConsumingService($e, $metadata);

        $this->entityDescriptor->addRoleDescriptor($e);
/*
        foreach ($metadata->getArray('contacts', []) as $contact) {
            if (array_key_exists('contactType', $contact) && array_key_exists('emailAddress', $contact)) {
                $this->addContact(Utils\Config\Metadata::getContact($contact));
            }
        }
*/
    }


    /**
     * Add metadata of a SAML 2.0 identity provider.
     *
     * @param array $metadata The metadata.
     */
    public function addMetadataIdP20(array $metadata): void
    {
        Assert::notNull($metadata['entityid']);
        Assert::notNull($metadata['metadata-set']);

        $metadata = Configuration::loadFromArray($metadata, $metadata['entityid']);
/*
        $e = new IDPSSODescriptor();
        $e->setProtocolSupportEnumeration(array_merge($e->getProtocolSupportEnumeration(), [Constants::NS_SAMLP]));

        if ($metadata->hasValue('sign.authnrequest')) {
            $e->setWantAuthnRequestsSigned($metadata->getBoolean('sign.authnrequest'));
        } elseif ($metadata->hasValue('redirect.sign')) {
            $e->setWantAuthnRequestsSigned($metadata->getBoolean('redirect.sign'));
        }

        $this->addExtensions($metadata, $e);

        $this->addCertificate($e, $metadata);

        if ($metadata->hasValue('ArtifactResolutionService')) {
            $e->setArtifactResolutionService(self::createEndpoints(
                $metadata->getEndpoints('ArtifactResolutionService'),
                true
            ));
        }

        $e->setSingleLogoutService(self::createEndpoints($metadata->getEndpoints('SingleLogoutService'), false));

        $e->setNameIDFormat($metadata->getOptionalArrayizeString('NameIDFormat', []));

        $e->setSingleSignOnService(self::createEndpoints($metadata->getEndpoints('SingleSignOnService'), false));

        $this->entityDescriptor->addRoleDescriptor($e);

        foreach ($metadata->getOptionalArray('contacts', []) as $contact) {
            if (array_key_exists('contactType', $contact) && array_key_exists('emailAddress', $contact)) {
                $this->addContact(Utils\Config\Metadata::getContact($contact));
            }
        }

        return;
*/
        if (array_key_exists('expire', $metadata)) {
            if ($metadata['expire'] - time() < $this->maxDuration) {
                $this->maxDuration = $metadata['expire'] - time();
            }
        }

        $wantAuthnRequestsSigned = null;
        if ($metadata->hasValue('sign.authnrequest')) {
            $wantAuthnRequestsSigned = $metadata->getBoolean('sign.authnrequest');
        } elseif ($metadata->hasValue('redirect.sign')) {
            $wantAuthnRequestsSigned = $metadata->getBoolean('redirect.sign');
        }

        $artifactResolutionService = [];
        if ($metadata->hasValue('ArtifactResolutionService')) {
            $artifactResolutionService = self::createEndpoints(
                $metadata->getEndpoints('ArtifactResolutionService'),
                true
            );
        }

        new EntityDescriptor(
            // EntityID
            $metadata->getString('entityid'),
            // ID
            null,
            // ValidUntil
            ($this->maxDuration !== null) ? (time() + $this->maxDuration) : null,
            // CacheDuration
            ($this->maxCache !== null) ? ('PT' . $this->maxCache . 'S') : null,
            // Extensions
//            $extensions,
            null,
            // RoleDescriptors
            [
                new IDPSSODescriptor(
                    self::createEndpoints($metadata->getEndpoints('SingleSignOnService'), SingleSignOnService::class),
                    [Constants::NS_SAMLP],
                    $wantAuthnRequestsSigned,
                    [],
                    [],
                    [],
                    [],
                    null,
                    null,
                    null,
                    null,
                    null,
                    [],
                    null,
                    [],
                    $artifactResolutionService,
                    self::createEndpoints($metadata->getEndpoints('SingleLogoutService'), SingleLogoutService::class),
                    [],
                    $metadata->getArrayizeString('NameIDFormat', [])
                )
            ],
            // AffiliationDescriptor
            null,
            // Organization
//            new Organization(),
            null,
            // Contacts
            [],
            // AdditionalMetadataLocation
            []
        );
    }


    /**
     * Add metadata of a SAML attribute authority.
     *
     * @param array $metadata The AttributeAuthorityDescriptor, in the format returned by
     * \SimpleSAML\Metadata\SAMLParser.
     */
    public function addAttributeAuthority(array $metadata): void
    {
        Assert::notNull($metadata['entityid']);
        Assert::notNull($metadata['metadata-set']);

        $metadata = Configuration::loadFromArray($metadata, $metadata['entityid']);

        $e = new AttributeAuthorityDescriptor();
        $e->setProtocolSupportEnumeration($metadata->getOptionalArray('protocols', [Constants::NS_SAMLP]));

        $this->addExtensions($metadata, $e);
        $this->addCertificate($e, $metadata);

        $e->setAttributeService(self::createEndpoints($metadata->getEndpoints('AttributeService'), false));
        $e->setAssertionIDRequestService(self::createEndpoints(
            $metadata->getEndpoints('AssertionIDRequestService'),
            false
        ));

        $e->setNameIDFormat($metadata->getOptionalArrayizeString('NameIDFormat', []));

        $this->entityDescriptor->addRoleDescriptor($e);
    }


    /**
     * Add contact information.
     *
     * Accepts a contact type, and a contact array that must be previously sanitized
     * by calling Utils\Config\Metadata::getContact().
     *
     * @param array  $details The details about the contact.
     */
    public function addContact(string $type, array $details): ContactPerson
    {
        Assert::notNull($details['contactType']);
        Assert::oneOf($details['contactType'], ContactPerson::CONTACT_TYPES);

        $details['ContactType'] = $type;
        return ContactPerson::fromArray($details);
/*
        $e = new ContactPerson();
        $e->setContactType($type);

        if (!empty($details['attributes'])) {
            $e->setContactPersonAttributes($details['attributes']);
        }

        if (isset($details['company'])) {
            $e->setCompany($details['company']);
        }
        if (isset($details['givenName'])) {
            $e->setGivenName($details['givenName']);
        }
        if (isset($details['surName'])) {
            $e->setSurName($details['surName']);
        }

        if (isset($details['emailAddress'])) {
            $eas = $details['emailAddress'];
            if (!is_array($eas)) {
                $eas = [$eas];
            }
            foreach ($eas as $ea) {
                $e->addEmailAddress($ea);
            }
        }

        if (isset($details['telephoneNumber'])) {
            $tlfNrs = $details['telephoneNumber'];
            if (!is_array($tlfNrs)) {
                $tlfNrs = [$tlfNrs];
            }
            foreach ($tlfNrs as $tlfNr) {
                $e->addTelephoneNumber($tlfNr);
            }
        }

        $this->entityDescriptor->addContactPerson($e);
*/
    }


    /**
     * Add a KeyDescriptor with an X509 certificate.
     *
     * @param \SimpleSAML\SAML2\XML\md\AbstractRoleDescriptor $rd The RoleDescriptor the certificate should be added to.
     * @param string                      $use The value of the 'use' attribute.
     * @param string                      $x509data The certificate data.
     * @param string|null                 $keyName The name of the key. Should be valid for usage in an ID attribute,
     *                                             e.g. not start with a digit.
     */
    private function addX509KeyDescriptor(
        RoleDescriptor $rd,
        string $use,
        string $x509data,
        ?string $keyName = null
    ): void {
        Assert::oneOf($use, ['encryption', 'signing']);

        $keyDescriptor = SAMLUtils::createKeyDescriptor($x509data);
        $keyDescriptor->setUse($use);
        $rd->addKeyDescriptor($keyDescriptor);
    }


    /**
     * Add a certificate.
     *
     * Helper function for adding a certificate to the metadata.
     *
     * @param \SimpleSAML\SAML2\XML\md\AbstractRoleDescriptor $rd The RoleDescriptor the certificate should be added to.
     * @param \SimpleSAML\Configuration    $metadata The metadata of the entity.
     */
    private function addCertificate(AbstractRoleDescriptor $rd, Configuration $metadata): void
    {
        $keys = $metadata->getPublicKeys();
        foreach ($keys as $key) {
            if ($key['type'] !== 'X509Certificate') {
                continue;
            }
            if (!isset($key['signing']) || $key['signing'] === true) {
                $this->addX509KeyDescriptor($rd, 'signing', $key['X509Certificate'], $key['name'] ?? null);
            }
            if (!isset($key['encryption']) || $key['encryption'] === true) {
                $this->addX509KeyDescriptor($rd, 'encryption', $key['X509Certificate'], $key['name'] ?? null);
            }
        }

        if ($metadata->hasValue('https.certData')) {
            $this->addX509KeyDescriptor($rd, 'signing', $metadata->getString('https.certData'));
        }
    }
}
