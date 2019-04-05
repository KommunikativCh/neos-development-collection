<?php
declare(strict_types=1);
namespace Neos\EventSourcedContentRepository\Domain\Context\ContentStream;

/*
 * This file is part of the Neos.ContentRepository package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\ContentRepository\Domain\ContentStream\ContentStreamIdentifier;
use Neos\EventSourcedContentRepository\Domain\ValueObject\CommandResult;
use Neos\EventSourcedContentRepository\Service\Infrastructure\ReadSideMemoryCacheManager;
use Neos\EventSourcing\Event\Decorator\EventWithIdentifier;
use Neos\EventSourcing\Event\DomainEvents;
use Neos\EventSourcing\EventStore\EventStoreManager;

/**
 * ContentStreamCommandHandler
 */
final class ContentStreamCommandHandler
{
    /**
     * @var ContentStreamRepository
     */
    protected $contentStreamRepository;

    /**
     * @var EventStoreManager
     */
    protected $eventStoreManager;

    /**
     * @var ReadSideMemoryCacheManager
     */
    protected $readSideMemoryCacheManager;


    public function __construct(ContentStreamRepository $contentStreamRepository, EventStoreManager $eventStoreManager, ReadSideMemoryCacheManager $readSideMemoryCacheManager)
    {
        $this->contentStreamRepository = $contentStreamRepository;
        $this->eventStoreManager = $eventStoreManager;
        $this->readSideMemoryCacheManager = $readSideMemoryCacheManager;
    }


    /**
     * @param Command\CreateContentStream $command
     * @return CommandResult
     * @throws ContentStreamAlreadyExists
     */
    public function handleCreateContentStream(Command\CreateContentStream $command): CommandResult
    {
        $this->readSideMemoryCacheManager->disableCache();

        $this->requireContentStreamToNotExistYet($command->getContentStreamIdentifier());
        $streamName = ContentStreamEventStreamName::fromContentStreamIdentifier($command->getContentStreamIdentifier())->getEventStreamName();
        $eventStore = $this->eventStoreManager->getEventStoreForStreamName($streamName);
        $events = DomainEvents::withSingleEvent(
            EventWithIdentifier::create(
                new Event\ContentStreamWasCreated(
                    $command->getContentStreamIdentifier(),
                    $command->getInitiatingUserIdentifier()
                )
            )
        );
        $eventStore->commit($streamName, $events);
        return CommandResult::fromPublishedEvents($events);
    }

    /**
     * @param Command\ForkContentStream $command
     * @return CommandResult
     * @throws ContentStreamAlreadyExists
     * @throws ContentStreamDoesNotExistYet
     */
    public function handleForkContentStream(Command\ForkContentStream $command): CommandResult
    {
        $this->readSideMemoryCacheManager->disableCache();

        $this->requireContentStreamToExist($command->getSourceContentStreamIdentifier());
        $this->requireContentStreamToNotExistYet($command->getContentStreamIdentifier());

        $sourceContentStream = $this->contentStreamRepository->findContentStream($command->getSourceContentStreamIdentifier());
        $sourceContentStreamVersion = $sourceContentStream !== null ? $sourceContentStream->getVersion() : -1;

        $streamName = ContentStreamEventStreamName::fromContentStreamIdentifier($command->getContentStreamIdentifier())->getEventStreamName();
        $eventStore = $this->eventStoreManager->getEventStoreForStreamName($streamName);

        $events = DomainEvents::withSingleEvent(
            EventWithIdentifier::create(
                new Event\ContentStreamWasForked(
                    $command->getContentStreamIdentifier(),
                    $command->getSourceContentStreamIdentifier(),
                    $sourceContentStreamVersion
                )
            )
        );
        $eventStore->commit($streamName, $events);
        return CommandResult::fromPublishedEvents($events);
    }


    /**
     * @param ContentStreamIdentifier $contentStreamIdentifier
     * @throws ContentStreamAlreadyExists
     */
    protected function requireContentStreamToNotExistYet(ContentStreamIdentifier $contentStreamIdentifier)
    {
        if ($this->contentStreamRepository->findContentStream($contentStreamIdentifier)) {
            throw new ContentStreamAlreadyExists('Content stream "' . $contentStreamIdentifier . '" already exists.', 1521386345);
        }
    }

    /**
     * @param ContentStreamIdentifier $contentStreamIdentifier
     * @throws ContentStreamDoesNotExistYet
     */
    protected function requireContentStreamToExist(ContentStreamIdentifier $contentStreamIdentifier)
    {
        if (!$this->contentStreamRepository->findContentStream($contentStreamIdentifier)) {
            throw new ContentStreamDoesNotExistYet('Content stream "' . $contentStreamIdentifier . '" does not exist yet.', 1521386692);
        }
    }
}
