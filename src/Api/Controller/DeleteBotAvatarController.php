<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Api\Controller;

use Flarum\Api\Controller\AbstractDeleteController;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Removes the uploaded bot avatar, mirroring core's DeleteLogoController.
 *
 * The setting is cleared before the file is deleted. If the delete fails — a
 * permissions problem on the assets disk, say — the forum is left pointing at
 * nothing rather than at a file it believes exists, which degrades to the initial
 * fallback instead of a broken image.
 */
class DeleteBotAvatarController extends AbstractDeleteController
{
    protected string $filePathSettingKey = 'ramon-chat.bot_avatar_path';
    protected Filesystem $uploadDir;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        Factory $filesystemFactory
    ) {
        $this->uploadDir = $filesystemFactory->disk('flarum-assets');
    }

    protected function delete(ServerRequestInterface $request): void
    {
        RequestUtil::getActor($request)->assertAdmin();

        $path = $this->settings->get($this->filePathSettingKey);

        $this->settings->set($this->filePathSettingKey, null);

        if ($path && $this->uploadDir->exists($path)) {
            $this->uploadDir->delete($path);
        }
    }
}
