<?php

declare(strict_types=1);

namespace EMS\CoreBundle\Service;

use Doctrine\Bundle\DoctrineBundle\Registry;
use EMS\CommonBundle\Common\Converter;
use EMS\CommonBundle\Helper\EmsFields;
use EMS\CommonBundle\Storage\NotFoundException;
use EMS\CoreBundle\Entity\CacheAssetExtractor;
use EMS\CoreBundle\Helper\AssetExtractor\ExtractedData;
use EMS\CoreBundle\Tika\TikaWrapper;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

class AssetExtractorService implements CacheWarmerInterface
{
    private const CONTENT_EP = '/tika';
    private const HELLO_EP = '/tika';
    private const META_EP = '/meta';
    private ?TikaWrapper $wrapper = null;

    public function __construct(private readonly RestClientService $rest, private readonly LoggerInterface $logger, private readonly Registry $doctrine, private readonly FileService $fileService, private readonly ?string $tikaServer, private readonly string $projectDir, private readonly ?string $tikaDownloadUrl)
    {
    }

    private function getTikaWrapper(): TikaWrapper
    {
        if ($this->wrapper instanceof TikaWrapper) {
            return $this->wrapper;
        }

        $filename = $this->projectDir.'/var/tika-app.jar';
        if (!\file_exists($filename) && $this->tikaDownloadUrl) {
            try {
                \file_put_contents($filename, \fopen($this->tikaDownloadUrl, 'r'));
            } catch (\Throwable) {
                if (\file_exists($filename)) {
                    \unlink($filename);
                }
            }
        }

        if (!\file_exists($filename)) {
            throw new \RuntimeException("Tika's jar not found");
        }

        $this->wrapper = new TikaWrapper($filename);

        return $this->wrapper;
    }

    /**
     * @return array{code:int,content:string}
     */
    public function hello(): array
    {
        if (!empty($this->tikaServer)) {
            $client = $this->rest->getClient($this->tikaServer, 3);
            $result = $client->get(self::HELLO_EP);

            return [
                'code' => $result->getStatusCode(),
                'content' => $result->getBody()->__toString(),
            ];
        } else {
            $temporaryName = \tempnam(\sys_get_temp_dir(), 'TikaWrapperTest');
            if (false === $temporaryName) {
                throw new \RuntimeException('It was possible to generate a temporary filename');
            }
            \file_put_contents($temporaryName, "elasticms's built in TikaWrapper : àêïôú");

            return [
                'code' => 200,
                'content' => self::cleanString($this->getTikaWrapper()->getText($temporaryName)),
            ];
        }
    }

    /**
     * @deprecated
     *
     * @return mixed[]
     */
    public function extractData(string $hash, string $file = null, bool $forced = false): array
    {
        return $this->extractMetaData($hash, $file, $forced)->getSource();
    }

    public function extractMetaData(string $hash, string $file = null, bool $forced = false): ExtractedData
    {
        $manager = $this->doctrine->getManager();
        $repository = $manager->getRepository(CacheAssetExtractor::class);

        /** @var ?CacheAssetExtractor $cacheData */
        $cacheData = $repository->findOneBy([
            'hash' => $hash,
        ]);

        if ($cacheData instanceof CacheAssetExtractor) {
            return new ExtractedData($cacheData->getData() ?? []);
        }

        if ((null === $file) || !\file_exists($file)) {
            $file = $this->fileService->getFile($hash);
        }

        if (!$file || !\file_exists($file)) {
            throw new NotFoundException($hash);
        }

        $filesize = \filesize($file);
        if (false === $filesize) {
            throw new \RuntimeException('Not able to get asset size');
        }
        if (!$forced && \filesize($file) > (3 * 1024 * 1024)) {
            $this->logger->warning('log.warning.asset_extract.file_to_large', [
                'filesize' => Converter::formatBytes($filesize),
                'max_size' => '3 MB',
            ]);

            return new ExtractedData([]);
        }

        $canBePersisted = true;
        if (!empty($this->tikaServer)) {
            try {
                $client = $this->rest->getClient($this->tikaServer, $forced ? 900 : 30);
                $body = \file_get_contents($file);
                $result = $client->put(self::META_EP, [
                    'body' => $body,
                    'headers' => [
                        'Accept' => 'application/json',
                    ],
                ]);
                $out = ExtractedData::fromJsonString($result->getBody()->__toString());

                $result = $client->put(self::CONTENT_EP, [
                    'body' => $body,
                    'headers' => [
                        'Accept' => 'text/plain',
                    ],
                ]);
                $out->setContent($result->getBody()->__toString());
            } catch (\Exception $e) {
                $this->logger->error('service.asset_extractor.extract_error', [
                    'file_hash' => $hash,
                    EmsFields::LOG_ERROR_MESSAGE_FIELD => $e->getMessage(),
                    EmsFields::LOG_EXCEPTION_FIELD => $e,
                    'tika' => 'server',
                ]);
                $canBePersisted = false;
            }
        } else {
            try {
                $out = ExtractedData::fromMetaString($this->getTikaWrapper()->getMetadata($file));
                if (!$out->hasContent()) {
                    $text = $this->getTikaWrapper()->getText($file);
                    if (!\mb_check_encoding($text)) {
                        $text = \mb_convert_encoding($text, \mb_internal_encoding(), 'ASCII');
                    }
                    $text = \preg_replace('/(\n)(\s*\n)+/', '${1}', $text);
                    $out->setContent($text ?? '');
                }
                if (!empty($out->getLocale())) {
                    $out->setLocale(AssetExtractorService::cleanString($this->getTikaWrapper()->getLanguage($file)));
                }
            } catch (\Exception $e) {
                $this->logger->error('service.asset_extractor.extract_error', [
                    'file_hash' => $hash,
                    EmsFields::LOG_ERROR_MESSAGE_FIELD => $e->getMessage(),
                    EmsFields::LOG_EXCEPTION_FIELD => $e,
                    'tika' => 'jar',
                ]);
                $canBePersisted = false;
            }
        }

        if ($canBePersisted && isset($out)) {
            try {
                $cacheData = new CacheAssetExtractor();
                $cacheData->setHash($hash);
                $cacheData->setData($out->getSource());
                $manager->persist($cacheData);
                $manager->flush();
            } catch (\Exception $e) {
                $this->logger->warning('service.asset_extractor.persist_error', [
                    'file_hash' => $hash,
                    EmsFields::LOG_ERROR_MESSAGE_FIELD => $e->getMessage(),
                    EmsFields::LOG_EXCEPTION_FIELD => $e,
                    'tika' => 'jar',
                ]);
            }
        }

        return $out ?? new ExtractedData([]);
    }

    private static function cleanString(string $string): string
    {
        if (!\mb_check_encoding($string)) {
            $string = \mb_convert_encoding($string, \mb_internal_encoding(), 'ASCII');
        }
        if (!\is_string($string)) {
            throw new \RuntimeException('Unexpected issue while multi byte encoded data');
        }

        return \preg_replace('/\n|\r/', '', $string) ?? '';
    }

    public function isOptional(): bool
    {
        return false;
    }

    public function warmUp(string $cacheDir): array
    {
        if (empty($this->tikaServer)) {
            $this->getTikaWrapper();
        }

        return [];
    }

    public function getMetaFromText(string $text): ExtractedData
    {
        if (!empty($this->tikaServer)) {
            $client = $this->rest->getClient($this->tikaServer, 15);
            $result = $client->put(self::META_EP, [
                'body' => $text,
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);
            $meta = ExtractedData::fromJsonString($result->getBody()->__toString());
        } else {
            $filename = \tempnam(\sys_get_temp_dir(), 'guess_locale');
            if (false === $filename) {
                throw new \RuntimeException('Unexpected false temporary filename');
            }
            if (false === \file_put_contents($filename, $text)) {
                throw new \RuntimeException('Unexpected false result on file_put_contents');
            }
            $meta = ExtractedData::fromMetaString($this->getTikaWrapper()->getMetadata($filename));
        }

        return $meta;
    }
}
