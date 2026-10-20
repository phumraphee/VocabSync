<?php
namespace VocabSync\Service\DataType;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\AbstractFactoryInterface;
use VocabSync\DataType\VocabSyncDataType;

class VocabSyncDataTypeFactory implements AbstractFactoryInterface
{
    public function canCreate(ContainerInterface $container, $requestedName)
    {
        return (bool) preg_match('/^vocabsync:\d+$/', (string) $requestedName);
    }

    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null)
    {
        $vocabId = (int) substr($requestedName, strlen('vocabsync:'));
        return new VocabSyncDataType($container->get('Omeka\Connection'), $vocabId);
    }
}
