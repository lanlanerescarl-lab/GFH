<?php

declare(strict_types=1);

namespace Gfh\Core\Security;

/** Thrown when the encryption key is unusable or a stored secret cannot be decrypted. */
final class SecretBoxException extends \RuntimeException
{
}
