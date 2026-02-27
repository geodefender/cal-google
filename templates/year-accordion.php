<div
    class="cal-google"
    id="<?php echo esc_attr($uid); ?>"
    style="--cal-bg: <?php echo esc_attr($bgColor); ?>; --cal-border: <?php echo esc_attr($borderColor); ?>; --cal-text: <?php echo esc_attr($textColor); ?>;"
>
    <?php foreach ($monthsToShow as $month) : ?>
        <details class="cal-google-month" <?php echo $month === $currentMonth ? 'open' : ''; ?>>
            <summary style="background-color: <?php echo esc_attr($bgColor); ?>; color: <?php echo esc_attr($textColor); ?>;"><?php echo esc_html($monthNames[$month] . ' ' . $year); ?><?php if ($showMonthCounter) : ?><span class="cal-google-month-counter"> (<?php echo esc_html((string) count($byMonth[$month])); ?>)</span><?php endif; ?></summary>
            <div class="cal-google-month-content">
                <?php if (empty($byMonth[$month])) : ?>
                    <p class="cal-google-empty"><?php echo esc_html($translations['no_events']); ?></p>
                <?php else : ?>
                    <?php foreach ($byMonth[$month] as $event) : ?>
                        <?php echo $this->render_partial('event-item.php', ['event' => $event]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </details>
    <?php endforeach; ?>
</div>
