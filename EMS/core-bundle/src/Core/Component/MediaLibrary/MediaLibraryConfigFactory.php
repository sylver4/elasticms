<?php

declare(strict_types=1);

namespace EMS\CoreBundle\Core\Component\MediaLibrary;

use EMS\CoreBundle\Core\Config\AbstractConfigFactory;
use EMS\CoreBundle\Core\Config\ConfigFactoryInterface;
use EMS\CoreBundle\Entity\ContentType;
use EMS\CoreBundle\Entity\FieldType;
use EMS\CoreBundle\Service\ContentTypeService;
use Symfony\Component\OptionsResolver\OptionsResolver;

class MediaLibraryConfigFactory extends AbstractConfigFactory implements ConfigFactoryInterface
{
    public function __construct(private readonly ContentTypeService $contentTypeService)
    {
    }

    /**
     * @param array{
     *   id: string,
     *   contentTypeName: string,
     *   fieldPath: string,
     *   fieldFolder: string,
     *   fieldFile: string,
     *   fieldPathOrder: ?string,
     *   template: ?string,
     *   context: array<string, mixed>,
     *   defaultValue: array<mixed>,
     *   searchSize: int,
     *   searchQuery: array<mixed>,
     * } $options
     */
    public function create(string $hash, array $options): MediaLibraryConfig
    {
        $contentType = $this->contentTypeService->giveByName($options['contentTypeName']);

        $config = new MediaLibraryConfig(
            $hash,
            (string) $options['id'],
            $contentType,
            $this->getField($contentType, $options['fieldPath'])->getName(),
            $this->getField($contentType, $options['fieldFolder'])->getName(),
            $this->getField($contentType, $options['fieldFile'])->getName()
        );

        $config->fieldPathOrder = $options['fieldPathOrder'];
        $config->defaultValue = $options['defaultValue'];
        $config->searchSize = $options['searchSize'];
        $config->searchQuery = $options['searchQuery'];
        $config->template = $options['template'];
        $config->context = $options['context'];

        return $config;
    }

    private function getField(ContentType $contentType, string $name): FieldType
    {
        $field = $contentType->getFieldType()->getChildByName($name);

        return $field ?: throw new \RuntimeException(\vsprintf('Field "%s" not found in "%s" contentType', [$name, $contentType->getName()]));
    }

    /** {@inheritdoc} */
    protected function resolveOptions(array $options): array
    {
        $resolver = new OptionsResolver();
        $resolver
            ->setDefaults([
                'contentTypeName' => 'media_file',
                'fieldPath' => 'media_path',
                'fieldPathOrder' => 'media_path.alpha_order',
                'fieldFolder' => 'media_folder',
                'fieldFile' => 'media_file',
                'defaultValue' => [],
                'searchSize' => MediaLibraryConfig::DEFAULT_SEARCH_SIZE,
                'searchQuery' => [],
                'context' => [],
                'template' => null,
            ])
            ->setRequired([
                'id',
                'contentTypeName',
                'fieldPath',
                'fieldFolder',
                'fieldFile',
            ])
            ->setAllowedTypes('defaultValue', 'array')
            ->setAllowedTypes('searchQuery', 'array')
            ->setAllowedTypes('searchSize', 'int')
            ->setAllowedTypes('context', 'array');

        /** @var array{contentTypeName: string} $resolved */
        $resolved = $resolver->resolve($options);

        return $resolved;
    }
}
