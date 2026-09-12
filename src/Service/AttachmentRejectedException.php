<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Thrown when an upload cannot be accepted. The message is written for the person who
 * tried to upload the file — the API passes it straight through to the UI.
 */
class AttachmentRejectedException extends \RuntimeException
{
}
