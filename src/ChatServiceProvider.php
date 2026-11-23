<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat;

use Flarum\Extension\ExtensionManager;
use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Foundation\Config;
use Flarum\Formatter\Formatter;
use Flarum\Http\UrlGenerator;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Store;
use Ramon\Chat\Service\RateLimiter;

class ChatServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        // O RateLimiter precisa do Store cru; o Flarum registra um Repository.
        $this->container->when(RateLimiter::class)
            ->needs(Store::class)
            ->give(fn () => $this->container->make(CacheRepository::class)->getStore());

        // Instância única para as policies compartilharem o memo. Em worker longo
        // ela sobrevive entre requests, por isso a chave é o objeto User, não o id.
        $this->container->singleton(Access\VisibilityCache::class);

        // Instância única para que limpar os cargos após uma escrita limpe também o
        // que já foi lido na request. A chave é o objeto Channel.
        $this->container->singleton(Service\ChannelRanks::class);

        // Horário dos canais. O calendário é o ernestdefoe/calendar quando ligado;
        // a classe confere isso sozinha, então outra extensão pode trocar o binding.
        // Instância única para policy e resource dividirem o memo (de validade curta).
        $this->container->singleton(Schedule\CalendarEvents::class, Schedule\ErnestDefoeCalendar::class);
        $this->container->singleton(Schedule\ChannelSchedule::class);
    }

    public function boot(): void
    {
        // HasFormattedContent guarda o formatter num static por classe; cada model
        // precisa receber o seu.
        $formatter = $this->container->make(Formatter::class);

        Message::setFormatter($formatter);
        MessageRevision::setFormatter($formatter);

        $url = $this->container->make(UrlGenerator::class);

        Channel::setServices(
            $this->container->make(ExtensionManager::class),
            $this->container->make(Config::class)
        );
        Upload::setUrlGenerator($url);
        Webhook::setUrlGenerator($url);
    }
}
