<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Integration;

use GraphqlOrm\Execution\IdentityMap;
use GraphqlOrm\Tests\Integration\Entity\Note;
use GraphqlOrm\Tests\Integration\Entity\User;
use GraphqlOrm\Tests\Integration\PostGraphile\Entity\RelayNote;
use GraphqlOrm\Tests\Integration\PostGraphile\Entity\RelayUser;
use PHPUnit\Framework\Attributes\Test;

/**
 * flush() against a real server: a note created, updated then deleted, read back by another manager each time.
 */
trait WritesEntities
{
    /**
     * @return array{class-string<Note|RelayNote>, class-string<User|RelayUser>} the note and the user of the API
     */
    abstract protected function noteAndUser(): array;

    #[Test]
    public function itCreatesUpdatesAndDeletesAnEntity(): void
    {
        [$noteClass, $userClass] = $this->noteAndUser();
        $manager = $this->manager(identityMap: new IdentityMap());
        $note = new $noteClass();
        $note->title = 'Note A';
        $note->author = $manager->getRepository($userClass)->find(1);

        $manager->persist($note);
        $manager->flush();

        try {
            self::assertIsInt($note->id);
            $read = $this->manager()->getRepository($noteClass)->find($note->id);
            self::assertSame(['Note A', false, 1], [$read?->title, $read?->done, $read?->author?->id]);

            $note->title = 'Note B';
            $note->done = true;
            $note->author = null;
            $manager->flush();

            $read = $this->manager()->getRepository($noteClass)->find($note->id);
            self::assertSame(['Note B', true, null], [$read?->title, $read?->done, $read?->author]);
        } finally {
            $manager->remove($note);
            $manager->flush();
        }

        self::assertNull($this->manager()->getRepository($noteClass)->find($note->id));
    }
}
