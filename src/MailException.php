<?php

declare(strict_types=1);

namespace Mercurius\MailMcp;

use RuntimeException;

/**
 * A mail operation failed. The message never carries a password.
 */
final class MailException extends RuntimeException {}
