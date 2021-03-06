<?php

namespace Oro\Bundle\ImportExportBundle\Strategy\Import;

use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\Common\Util\ClassUtils;
use Doctrine\ORM\EntityManager;

use Symfony\Component\Security\Acl\Exception\InvalidDomainObjectException;
use Symfony\Component\Security\Acl\Voter\FieldVote;
use Symfony\Component\Translation\TranslatorInterface;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\ValidatorInterface;

use Oro\Bundle\EntityBundle\Helper\FieldHelper;
use Oro\Bundle\EntityConfigBundle\Provider\ConfigProvider;
use Oro\Bundle\ImportExportBundle\Context\BatchContextInterface;
use Oro\Bundle\ImportExportBundle\Context\ContextInterface;
use Oro\Bundle\ImportExportBundle\Exception\InvalidArgumentException;
use Oro\Bundle\ImportExportBundle\Exception\LogicException;
use Oro\Bundle\ImportExportBundle\Converter\ConfigurableTableDataConverter;
use Oro\Bundle\SecurityBundle\SecurityFacade;

class ImportStrategyHelper
{
    /**
     * @var ManagerRegistry
     */
    protected $managerRegistry;

    /**
     * @var ValidatorInterface
     */
    protected $validator;

    /**
     * @var TranslatorInterface
     */
    protected $translator;

    /**
     * @var FieldHelper
     */
    protected $fieldHelper;

    /** @var ConfigProvider */
    protected $extendConfigProvider;

    /**
     * @var ConfigurableTableDataConverter
     */
    protected $configurableDataConverter;

    /**
     * @var SecurityFacade
     */
    protected $securityFacade;

    /**
     * @param ManagerRegistry $managerRegistry
     * @param ValidatorInterface $validator
     * @param TranslatorInterface $translator
     * @param FieldHelper $fieldHelper
     * @param ConfigurableTableDataConverter $configurableDataConverter
     * @param SecurityFacade $securityFacade
     */
    public function __construct(
        ManagerRegistry $managerRegistry,
        ValidatorInterface $validator,
        TranslatorInterface $translator,
        FieldHelper $fieldHelper,
        ConfigurableTableDataConverter $configurableDataConverter,
        SecurityFacade $securityFacade
    ) {
        $this->managerRegistry = $managerRegistry;
        $this->validator = $validator;
        $this->translator = $translator;
        $this->fieldHelper = $fieldHelper;
        $this->configurableDataConverter = $configurableDataConverter;
        $this->securityFacade = $securityFacade;
    }

    /**
     * @param ConfigProvider $extendConfigProvider
     */
    public function setConfigProvider(ConfigProvider $extendConfigProvider)
    {
        $this->extendConfigProvider = $extendConfigProvider;
    }

    /**
     * Checks if an access to a resource is granted to the caller
     *
     * @param string|string[] $attributes Can be a role name(s), permission name(s), an ACL annotation id,
     *                                    string in format "permission;descriptor"
     *                                    (VIEW;entity:AcmeDemoBundle:AcmeEntity, EDIT;action:acme_action)
     *                                    or something else, it depends on registered security voters
     * @param  object         $obj        A domain object, object identity or object identity descriptor
     *
     * @param  string         $property
     * @return bool
     */
    public function isGranted($attributes, $obj, $property = null)
    {
        if (!$this->securityFacade->hasLoggedUser()) {
            return true;
        }
        if ($property && !($obj instanceof FieldVote)) {
            $obj = new FieldVote($obj, $property);
        }

        try {
            return $this->securityFacade->isGranted($attributes, $obj);
        } catch (InvalidDomainObjectException $exception) {
            // if object do not have identity we skipp check
            return true;
        }
    }

    /**
     * @param string $entityClass
     * @return EntityManager
     * @throws LogicException
     */
    public function getEntityManager($entityClass)
    {
        $entityManager = $this->managerRegistry->getManagerForClass($entityClass);
        if (!$entityManager) {
            throw new LogicException(
                sprintf('Can\'t find entity manager for %s', $entityClass)
            );
        }

        return $entityManager;
    }

    /**
     * @param object $basicEntity
     * @param object $importedEntity
     * @param array $excludedProperties
     * @throws InvalidArgumentException
     */
    public function importEntity($basicEntity, $importedEntity, array $excludedProperties = array())
    {
        $basicEntityClass = ClassUtils::getClass($basicEntity);
        if ($basicEntityClass != ClassUtils::getClass($importedEntity)) {
            throw new InvalidArgumentException('Basic and imported entities must be instances of the same class');
        }

        $entityMetadata = $this->getEntityManager($basicEntityClass)->getClassMetadata($basicEntityClass);
        $importedEntityProperties = array_diff(
            array_merge(
                $entityMetadata->getFieldNames(),
                $entityMetadata->getAssociationNames()
            ),
            $excludedProperties
        );
        foreach ($importedEntityProperties as $propertyName) {
            // we should not overwrite deleted fields
            if ($this->isDeletedField($basicEntityClass, $propertyName)) {
                continue;
            }

            $importedValue = $this->fieldHelper->getObjectValue($importedEntity, $propertyName);
            $this->fieldHelper->setObjectValue($basicEntity, $propertyName, $importedValue);
        }
    }

    /**
     * Validate entity, returns list of errors or null
     *
     * @param object $entity
     * @param null   $groups
     *
     * @return array|null
     */
    public function validateEntity($entity, $groups = null)
    {
        $violations = $this->validator->validate($entity, $groups);
        if (count($violations)) {
            $errors = [];

            /** @var ConstraintViolationInterface $violation */
            foreach ($violations as $violation) {
                $propertyPath = $violation->getPropertyPath();
                if ($propertyPath && is_object($entity)) {
                    $fieldHeader = $this->configurableDataConverter->getFieldHeaderWithRelation(
                        ClassUtils::getClass($entity),
                        $propertyPath
                    );
                    $propertyPath = ($fieldHeader ?: $propertyPath) . ': ';
                }
                $errors[] = $propertyPath . $violation->getMessage();
            }
            return $errors;
        }

        return null;
    }

    /**
     * @param array $validationErrors
     * @param ContextInterface $context
     * @param string|null $errorPrefix
     */
    public function addValidationErrors(array $validationErrors, ContextInterface $context, $errorPrefix = null)
    {
        $batchSize = null;
        $batchNumber = null;
        if ($context instanceof BatchContextInterface) {
            $batchSize = $context->getBatchSize();
            $batchNumber = $context->getBatchNumber();
        }

        if (null === $errorPrefix) {
            $rowNumber = $context->getReadOffset();
            if ($batchNumber && $batchSize) {
                $rowNumber += --$batchNumber * $batchSize;
            }
            $errorPrefix = $this->translator->trans(
                'oro.importexport.import.error %number%',
                array(
                    '%number%' => $rowNumber
                )
            );
        }
        foreach ($validationErrors as $validationError) {
            $context->addError($errorPrefix . ' ' . $validationError);
        }
    }

    /**
     * @return mixed|null
     */
    public function getLoggedUser()
    {
        return $this->securityFacade->getLoggedUser();
    }

    /**
     * Check if given class field is deleted
     *
     * @param string $className FQCN
     * @param string $fieldName
     *
     * @return bool
     */
    protected function isDeletedField($className, $fieldName)
    {
        if ($this->extendConfigProvider->hasConfig($className, $fieldName)) {
            return $this->extendConfigProvider->getConfig($className, $fieldName)->is('is_deleted');
        }

        return false;
    }
}
