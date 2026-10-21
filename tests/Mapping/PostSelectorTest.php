<?php

declare(strict_types=1);

namespace VastsverigeEvents\Tests\Mapping;

use PHPUnit\Framework\TestCase;
use VastsverigeEvents\Mapping\PostChoice;
use VastsverigeEvents\Mapping\PostSelector;

final class PostSelectorTest extends TestCase
{
    private PostSelector $selector;

    protected function setUp(): void
    {
        $this->selector = new PostSelector();
    }

    /**
     * @return array{id: int, trashed: bool, byImport: bool}
     */
    private function post(int $id, bool $trashed = false, bool $byImport = false): array
    {
        return ['id' => $id, 'trashed' => $trashed, 'byImport' => $byImport];
    }

    public function testNoCandidatesCreatesANewPost(): void
    {
        $choice = $this->selector->choose([]);

        self::assertSame(PostChoice::CREATE, $choice->action);
    }

    public function testASingleLivePostIsUpdated(): void
    {
        $choice = $this->selector->choose([$this->post(7)]);

        self::assertSame(PostChoice::UPDATE, $choice->action);
        self::assertSame(7, $choice->postId);
        self::assertSame([], $choice->duplicates);
    }

    public function testLowestLivePostWinsAndTheRestAreDuplicates(): void
    {
        $choice = $this->selector->choose([$this->post(30), $this->post(10), $this->post(20)]);

        self::assertSame(10, $choice->postId);
        self::assertSame([20, 30], $choice->duplicates);
    }

    public function testLivePostBeatsATrashedOne(): void
    {
        $choice = $this->selector->choose([$this->post(5, true, true), $this->post(9)]);

        self::assertSame(PostChoice::UPDATE, $choice->action);
        self::assertSame(9, $choice->postId);
        self::assertSame([], $choice->duplicates);
    }

    public function testPostTrashedByTheImporterIsRestored(): void
    {
        $choice = $this->selector->choose([$this->post(5, true, true)]);

        self::assertSame(PostChoice::RESTORE, $choice->action);
        self::assertSame(5, $choice->postId);
    }

    public function testPostTrashedByAnEditorIsLeftAlone(): void
    {
        $choice = $this->selector->choose([$this->post(5, true, false)]);

        self::assertSame(PostChoice::SKIP, $choice->action);
    }

    public function testImporterTrashedPostIsPreferredForRestoreOverEditorTrashedOne(): void
    {
        $choice = $this->selector->choose([$this->post(3, true, false), $this->post(8, true, true)]);

        self::assertSame(PostChoice::RESTORE, $choice->action);
        self::assertSame(8, $choice->postId);
    }
}
