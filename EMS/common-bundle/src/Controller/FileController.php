<?php

namespace EMS\CommonBundle\Controller;

use EMS\CommonBundle\Helper\EmsFields;
use EMS\CommonBundle\Storage\Processor\Processor;
use EMS\CommonBundle\Twig\RequestRuntime;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class FileController extends AbstractController
{
    public function __construct(private readonly Processor $processor, private readonly RequestRuntime $requestRuntime)
    {
    }

    public function asset(Request $request, string $hash, string $hash_config, string $filename): Response
    {
        $this->closeSession($request);

        return $this->processor->getResponse($request, $hash, $hash_config, $filename, true);
    }

    /**
     * @param mixed[] $fileField
     * @param mixed[] $configArray
     */
    public function resolveAsset(Request $request, array $fileField, array $configArray = []): Response
    {
        $this->closeSession($request);

        return $this->processor->resolveAndGetResponse($request, $fileField, $configArray);
    }

    public function view(Request $request, string $sha1): Response
    {
        @\trigger_error('FileController::view is deprecated use the ems_asset twig filter to generate the route', E_USER_DEPRECATED);

        $this->closeSession($request);

        return $this->getFile($request, $sha1, ResponseHeaderBag::DISPOSITION_INLINE);
    }

    public function download(Request $request, string $sha1): Response
    {
        @\trigger_error('FileController::download is deprecated use the ems_asset twig filter to generate the route', E_USER_DEPRECATED);

        $this->closeSession($request);

        return $this->getFile($request, $sha1, ResponseHeaderBag::DISPOSITION_ATTACHMENT);
    }

    private function getFile(Request $request, string $hash, string $disposition): Response
    {
        @\trigger_error('FileController::download is deprecated use the ems_asset twig filter to generate the route', E_USER_DEPRECATED);

        $name = $request->query->get('name', 'upload.bin');
        $type = $request->query->get('type', 'application/bin');

        return $this->redirect($this->requestRuntime->assetPath([
            EmsFields::CONTENT_FILE_HASH_FIELD => $hash,
            EmsFields::CONTENT_FILE_NAME_FIELD => $name,
            EmsFields::CONTENT_MIME_TYPE_FIELD => $type,
        ], [
            EmsFields::ASSET_CONFIG_DISPOSITION => $disposition,
        ]));
    }

    /**
     * http://blog.alterphp.com/2012/08/how-to-deal-with-asynchronous-request.html.
     */
    private function closeSession(Request $request): void
    {
        if (!$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        if ($session->isStarted()) {
            $session->save();
        }
    }
}
