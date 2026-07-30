<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Api\Controller;

use Flarum\Foundation\AbstractImageValidator;

/**
 * Validates the uploaded bot avatar.
 *
 * Core's AbstractImageValidator is the whole implementation: it checks the upload
 * error, the byte size *before decoding* — so an oversized file is rejected without
 * being read into memory — and the real mime type read from the file's own bytes
 * rather than the `Content-Type` the browser claimed.
 *
 * Subclassed rather than used directly because the validator name is what appears
 * in the error message, and "logo" would be a confusing thing to be told about an
 * avatar.
 */
class BotAvatarValidator extends AbstractImageValidator
{
}
