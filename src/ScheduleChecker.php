<?php
declare(strict_types=1);

namespace Jobby;

use Cron\CronExpression;

class ScheduleChecker
{
    private \DateTimeImmutable $now;

    public function __construct(\DateTimeImmutable $now = null)
    {
        $this->now = $now ?? new \DateTimeImmutable('now');
    }

    /**
     * @param string|callable(\DateTimeImmutable):bool $schedule
     */
    public function isDue($schedule): bool
    {
        if (is_callable($schedule)) {
            return call_user_func($schedule, $this->now);
        }

        $dateTime = \DateTime::createFromFormat('Y-m-d H:i:s', $schedule);
        if ($dateTime !== false) {
            return $dateTime->format('Y-m-d H:i') == $this->now->format('Y-m-d H:i');
        }

        return CronExpression::factory($schedule)->isDue($this->now);
    }
}
