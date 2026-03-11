<?php

declare(strict_types=1);

namespace arabcoders\database\Orm;

final class EntityEvent
{
    public const string PRE_INSERT = 'orm.entity.pre_insert';
    public const string POST_INSERT = 'orm.entity.post_insert';
    public const string PRE_UPDATE = 'orm.entity.pre_update';
    public const string POST_UPDATE = 'orm.entity.post_update';
    public const string PRE_DELETE = 'orm.entity.pre_delete';
    public const string POST_DELETE = 'orm.entity.post_delete';

    public function __construct(
        private object $entity,
        private string $eventName,
    ) {}

    public function entity(): object
    {
        return $this->entity;
    }

    public function eventName(): string
    {
        return $this->eventName;
    }
}
