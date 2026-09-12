<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Attachment;
use App\Repository\AttachmentRepository;
use App\Service\AttachmentStorage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Streams attachment bytes.
 *
 * Uploads live outside the document root, so this controller is the only way to read them.
 * That costs a PHP process per file but buys three things: the web server will never execute
 * an upload, `nosniff` stops a browser from reinterpreting a document as HTML, and anything
 * that is not audio, video or an image is sent as a download rather than rendered in place.
 */
class AttachmentController extends AbstractController
{
    public function __construct(
        private readonly AttachmentRepository $attachments,
        private readonly AttachmentStorage $storage,
    ) {
    }

    #[Route('/attachments/{id}', name: 'app_attachment_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $attachment = $this->find($id);

        return $this->respond(
            $attachment,
            $attachment->isInlineRenderable()
                ? ResponseHeaderBag::DISPOSITION_INLINE
                : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
        );
    }

    #[Route('/attachments/{id}/download', name: 'app_attachment_download', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function download(int $id): Response
    {
        return $this->respond($this->find($id), ResponseHeaderBag::DISPOSITION_ATTACHMENT);
    }

    private function respond(Attachment $attachment, string $disposition): Response
    {
        $path = $this->storage->pathFor($attachment);
        if (!is_file($path)) {
            throw $this->createNotFoundException('The file behind this attachment is missing.');
        }

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition($disposition, $attachment->getOriginalFilename());
        $response->headers->set('Content-Type', $attachment->getMimeType());
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // Recordings never change once uploaded, so let the browser keep them; `private`
        // because a shared cache should not hold on to another visitor's attachment.
        $response->setPrivate();
        $response->setMaxAge(86400);
        // Lets <audio>/<video> seek without re-downloading the whole file.
        $response->headers->set('Accept-Ranges', 'bytes');

        return $response;
    }

    private function find(int $id): Attachment
    {
        return $this->attachments->find($id) ?? throw $this->createNotFoundException('That attachment does not exist.');
    }
}
