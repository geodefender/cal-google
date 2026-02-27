<?php
final class Event
{
    /** @var array<int,DateTimeImmutable> */
    public array $exdate;

    /** @var array<int,DateTimeImmutable> */
    public array $rdate;

    public function __construct(
        public string $summary,
        public string $description,
        public string $location,
        public string $url,
        public DateTimeImmutable $start,
        public ?DateTimeImmutable $end = null,
        public string $uid = '',
        public string $rrule = '',
        array $exdate = [],
        array $rdate = []
    ) {
        $this->exdate = $exdate;
        $this->rdate = $rdate;
    }

    public function isAllDay(): bool
    {
        if ($this->start->format('His') !== '000000') {
            return false;
        }

        return $this->end === null || $this->end->format('His') === '000000';
    }

    public function month(): int
    {
        return (int) $this->start->format('n');
    }

    public function year(): int
    {
        return (int) $this->start->format('Y');
    }

    public function withStartAndEnd(DateTimeImmutable $start, ?DateTimeImmutable $end): self
    {
        return new self($this->summary, $this->description, $this->location, $this->url, $start, $end, $this->uid, $this->rrule, $this->exdate, $this->rdate);
    }
}
