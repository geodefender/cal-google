<div
    class="cal-google"
    id="<?php echo esc_attr($uid); ?>"
    style="--cal-bg: <?php echo esc_attr($bgColor); ?>; --cal-border: <?php echo esc_attr($borderColor); ?>; --cal-text: <?php echo esc_attr($textColor); ?>;"
>
    <section class="cal-google-list">
        <?php if (empty($eventItems)) : ?>
            <p class="cal-google-empty"><?php echo esc_html($translations['no_events']); ?></p>
        <?php elseif (! $groupByMonth) : ?>
            <div class="cal-google-list-month-events">
                <?php foreach ($eventItems as $event) : ?>
                    <?php echo $this->render_partial('event-item.php', ['event' => $event]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <?php endforeach; ?>
            </div>
        <?php else : ?>
            <?php foreach ($monthsToShow as $month) : ?>
                <div class="cal-google-list-month">
                    <h3 class="cal-google-list-month-title"><?php echo esc_html($monthNames[$month] . ' ' . $year); ?><?php if ($showMonthCounter) : ?><span class="cal-google-month-counter"> (<?php echo esc_html((string) count($byMonth[$month] ?? [])); ?>)</span><?php endif; ?></h3>
                    <div class="cal-google-list-month-events">
                        <?php if (empty($byMonth[$month])) : ?>
                            <p class="cal-google-empty"><?php echo esc_html($translations['no_events']); ?></p>
                        <?php else : ?>
                            <?php foreach ($byMonth[$month] as $event) : ?>
                                <?php echo $this->render_partial('event-item.php', ['event' => $event]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
</div>
