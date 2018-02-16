<?php

namespace Paymaxi\DoctrineEncryptBundle\Subscribers;

use Doctrine\Common\EventSubscriber;
use Doctrine\Common\Proxy\Proxy;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Paymaxi\DoctrineEncryptBundle\Encryptors\EncryptorInterface;
use Paymaxi\DoctrineEncryptBundle\Services\PropertyFilter;
use ReflectionClass;
use Symfony\Component\PropertyAccess\PropertyAccessor;

/**
 * Doctrine event subscriber which encrypt/decrypt entities
 */
class DoctrineEncryptSubscriber implements EventSubscriber
{
    /** @var array */
    private static $ignore = [];

    /** @var array */
    private static $cachedProperties = [];

    /**
     * Encryptor interface namespace
     */
    public const ENCRYPTOR_INTERFACE_NS = EncryptorInterface::class;

    /**
     * Encryptor
     *
     * @var EncryptorInterface
     */
    private $encryptor;

    /**
     * @var EntityManager
     */
    private $entityManager;

    /**
     * Secret key
     *
     * @var string
     */
    private $secretKey;

    /**
     * Used for restoring the encryptor after changing it
     *
     * @var string
     */
    private $restoreEncryptor;

    /**
     * Count amount of decrypted values in this service
     *
     * @var integer
     */
    public $decryptCounter = 0;

    /**
     * Count amount of encrypted values in this service
     *
     * @var integer
     */
    public $encryptCounter = 0;

    /** @var PropertyAccessor */
    private $accessor;

    /**
     * Initialization of subscriber
     *
     * @param EntityManager $entityManager
     * @param string $encryptorClass The encryptor class.  This can be empty if a service is being provided.
     * @param string $secretKey The secret key.
     * @param EncryptorInterface|NULL $service (Optional)  An EncryptorInterface.
     *
     * This allows for the use of dependency injection for the encrypters.
     */
    public function __construct(
        EntityManager $entityManager,
        $encryptorClass,
        $secretKey,
        EncryptorInterface $service = null
    ) {
        $this->entityManager = $entityManager;
        $this->secretKey = $secretKey;

        if ($service instanceof EncryptorInterface) {
            $this->encryptor = $service;
        } else {
            $this->encryptor = $this->encryptorFactory($encryptorClass, $secretKey);
        }

        $this->restoreEncryptor = $this->encryptor;
        $this->accessor = new PropertyAccessor();
    }

    /**
     * Change the encryptor
     *
     * @param string|null $encryptorClass
     */
    public function setEncryptor($encryptorClass): void
    {
        if (null === $encryptorClass) {
            $this->encryptor = $encryptorClass;
        } else {
            $this->encryptor = $this->encryptorFactory($encryptorClass, $this->secretKey);
        }
    }

    /**
     * Get the current encryptor
     */
    public function getEncryptor()
    {
        if (!empty($this->encryptor)) {
            return \get_class($this->encryptor);
        }

        return null;
    }

    /**
     * Restore encryptor set in config
     */
    public function restoreEncryptor(): void
    {
        $this->encryptor = $this->restoreEncryptor;
    }

    /**
     * Listen a postUpdate lifecycle event.
     * Decrypt entities property's values when post updated.
     *
     * So for example after form submit the preUpdate encrypted the entity
     * We have to decrypt them before showing them again.
     *
     * @param LifecycleEventArgs $args
     */
    public function postUpdate(LifecycleEventArgs $args): void
    {
        $entity = $args->getEntity();
        $this->processFields($entity, false);
    }

    /**
     * Listen a preUpdate lifecycle event.
     * Encrypt entities property's values on preUpdate, so they will be stored encrypted
     *
     * @param PreUpdateEventArgs $args
     */
    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getEntity();
        $this->processFields($entity);
    }

    /**
     * Listen a postLoad lifecycle event.
     * Decrypt entities property's values when loaded into the entity manager
     *
     * @param LifecycleEventArgs $args
     */
    public function postLoad(LifecycleEventArgs $args): void
    {

        //Get entity and process fields
        $entity = $args->getEntity();
        $this->processFields($entity, false);
    }

    /**
     * Listen to preflush event
     * Encrypt entities that are inserted into the database
     *
     * @param PreFlushEventArgs $preFlushEventArgs
     */
    public function preFlush(PreFlushEventArgs $preFlushEventArgs): void
    {
        $unitOfWork = $preFlushEventArgs->getEntityManager()->getUnitOfWork();
        foreach ($unitOfWork->getIdentityMap() as $className => $entities) {
            $class = $preFlushEventArgs->getEntityManager()->getClassMetadata($className);
            if ($class->isReadOnly) {
                continue;
            }

            foreach ($entities as $entity) {
                if ($entity instanceof Proxy && !$entity->__isInitialized__) {
                    continue;
                }
                $this->processFields($entity);
            }
        }
    }

    /**
     * Listen to postFlush event
     * Decrypt entities that after inserted into the database
     *
     * @param PostFlushEventArgs $postFlushEventArgs
     */
    public function postFlush(PostFlushEventArgs $postFlushEventArgs): void
    {
        $unitOfWork = $postFlushEventArgs->getEntityManager()->getUnitOfWork();
        foreach ($unitOfWork->getIdentityMap() as $entityMap) {
            foreach ($entityMap as $entity) {
                $this->processFields($entity, false);
            }
        }
    }

    /**
     * Realization of EventSubscriber interface method.
     *
     * @return array Return all events which this subscriber is listening
     */
    public function getSubscribedEvents()
    {
        return [
            Events::postUpdate,
            Events::preUpdate,
            Events::postLoad,
            Events::preFlush,
            Events::postFlush
        ];
    }

    /**
     * Process (encrypt/decrypt) entities fields
     *
     * @param Object $entity doctrine entity
     * @param Boolean $isEncryptOperation If true - encrypt, false - decrypt entity
     *
     * @return object|null
     */
    public function processFields($entity, $isEncryptOperation = true)
    {
        $class = \get_class($entity);

        if (null === $this->encryptor || in_array($class, self::$ignore)) {
            return null;
        }

        if (false === array_key_exists($class, self::$cachedProperties)) {
            $metadata = $this->entityManager->getClassMetadata($class);
            $properties = PropertyFilter::filter($metadata);

            self::$cachedProperties[$class] = $properties;
        }

        /** @var \ReflectionProperty[] $properties */
        $properties = self::$cachedProperties[$class];
        $accessor = $this->accessor;

        //Check which operation to be used
        $encryptorMethod = $isEncryptOperation ? 'encrypt' : 'decrypt';

        //Foreach property in the reflection class
        foreach ($properties as $property) {
            $propertyName = $property->getName();
            /**
             * If followed standards, method name is getPropertyName, the propertyName is lowerCamelCase
             * So just uppercase first character of the property, later on get and set{$methodName} wil be used
             */
            if (!$accessor->isReadable($entity, $propertyName)) {
                throw new \RuntimeException('Property could not be read.');
            }

            $getInformation = $accessor->getValue($entity, $propertyName);

            /**
             * Then decrypt, encrypt the information if not empty, information is an string and the <ENC> tag is there (decrypt) or not (encrypt).
             * The <ENC> will be added at the end of an encrypted string so it is marked as encrypted. Also protects against double encryption/decryption
             */
            if ('decrypt' === $encryptorMethod) {
                if (null !== $getInformation and !empty($getInformation)) {
                    if ('<ENC>' === substr($getInformation, -5)) {
                        $this->decryptCounter++;
                        $currentPropValue = $this->encryptor->decrypt(substr($getInformation, 0, -5));
                        $accessor->setValue($entity, $propertyName, $currentPropValue);
                    }
                }
            } else {
                if (null !== $getInformation and !empty($getInformation)) {
                    if ('<ENC>' !== substr($getInformation, -5)) {
                        $this->encryptCounter++;
                        $currentPropValue = $this->encryptor->encrypt($getInformation);
                        $accessor->setValue($entity, $propertyName, $currentPropValue);
                    }
                }
            }
        }

        return $entity;
    }

    /**
     * Recursive function to get an associative array of class properties
     * including inherited ones from extended classes
     *
     * @param string $className Class name
     *
     * @return array
     * @throws \ReflectionException
     */
    public function getClassProperties($className): array
    {
        $reflectionClass = new ReflectionClass($className);
        $properties = $reflectionClass->getProperties();
        $propertiesArray = [];

        foreach ($properties as $property) {
            $propertyName = $property->getName();
            $propertiesArray[$propertyName] = $property;
        }

        if ($parentClass = $reflectionClass->getParentClass()) {
            $parentPropertiesArray = $this->getClassProperties($parentClass->getName());
            if (\count($parentPropertiesArray) > 0) {
                $propertiesArray = array_merge($parentPropertiesArray, $propertiesArray);
            }
        }

        return $propertiesArray;
    }

    /**
     * Encryptor factory. Checks and create needed encryptor
     *
     * @param string $classFullName Encryptor namespace and name
     * @param string $secretKey Secret key for encryptor
     *
     * @return EncryptorInterface
     * @throws \ReflectionException
     */
    private function encryptorFactory($classFullName, $secretKey): EncryptorInterface
    {
        $refClass = new \ReflectionClass($classFullName);
        if ($refClass->implementsInterface(self::ENCRYPTOR_INTERFACE_NS)) {
            return new $classFullName($secretKey);
        }

        throw new \RuntimeException('Encryptor must implements interface EncryptorInterface');
    }
}
