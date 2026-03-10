<?php

declare(strict_types=1);

namespace App\JsonLd;

/**
 * IRI constants for JSON-LD vocabularies used by Hydra APIs.
 */
final class Vocab
{
    public const HYDRA = 'http://www.w3.org/ns/hydra/core#';
    public const RDFS = 'http://www.w3.org/2000/01/rdf-schema#';
    public const OWL = 'http://www.w3.org/2002/07/owl#';
    public const XSD = 'http://www.w3.org/2001/XMLSchema#';

    // Hydra classes
    public const API_DOCUMENTATION = self::HYDRA.'ApiDocumentation';
    public const CLASS_ = self::HYDRA.'Class';
    public const COLLECTION = self::HYDRA.'Collection';
    public const SUPPORTED_PROPERTY = self::HYDRA.'SupportedProperty';
    public const LINK = self::HYDRA.'Link';
    public const OPERATION = self::HYDRA.'Operation';
    public const IRI_TEMPLATE = self::HYDRA.'IriTemplate';
    public const IRI_TEMPLATE_MAPPING = self::HYDRA.'IriTemplateMapping';

    // Hydra properties
    public const SUPPORTED_CLASS = self::HYDRA.'supportedClass';
    public const SUPPORTED_PROPERTY_PROP = self::HYDRA.'supportedProperty';
    public const SUPPORTED_OPERATION = self::HYDRA.'supportedOperation';
    public const PROPERTY = self::HYDRA.'property';
    public const TITLE = self::HYDRA.'title';
    public const DESCRIPTION = self::HYDRA.'description';
    public const METHOD = self::HYDRA.'method';
    public const EXPECTS = self::HYDRA.'expects';
    public const RETURNS = self::HYDRA.'returns';
    public const READABLE = self::HYDRA.'readable';
    public const WRITEABLE = self::HYDRA.'writeable';
    public const REQUIRED = self::HYDRA.'required';
    public const TEMPLATE = self::HYDRA.'template';
    public const MAPPING = self::HYDRA.'mapping';
    public const VARIABLE = self::HYDRA.'variable';
    public const SEARCH = self::HYDRA.'search';
    public const MEMBER = self::HYDRA.'member';
    public const OPERATION_PROP = self::HYDRA.'operation';
    public const MEMBER_ASSERTION = self::HYDRA.'memberAssertion';

    // RDF
    public const RDF = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';
    public const RDF_TYPE = self::RDF.'type';

    // RDFS
    public const LABEL = self::RDFS.'label';
    public const RANGE = self::RDFS.'range';

    // OWL
    public const EQUIVALENT_CLASS = self::OWL.'equivalentClass';
    public const ALL_VALUES_FROM = self::OWL.'allValuesFrom';
}
