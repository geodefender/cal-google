<div class="cal-google" id="<?php echo esc_attr($uid); ?>">
    <style>
        #<?php echo esc_html($uid); ?> .cal-google-list { border: 1px solid <?php echo esc_html($borderColor); ?>; border-radius: 8px; background: #fff; overflow: hidden; }
        #<?php echo esc_html($uid); ?> .cal-google-list-month { margin: 0; }
        #<?php echo esc_html($uid); ?> .cal-google-list-month-title { margin: 0; padding: 12px 14px; background: <?php echo esc_html($bgColor); ?>; color: <?php echo esc_html($textColor); ?>; font-weight: 600; }
        #<?php echo esc_html($uid); ?> .cal-google-list-month-events { padding: 0 14px; }
        #<?php echo esc_html($uid); ?> .cal-google-event { padding: 10px 0; border-bottom: 1px solid #ececec; }
        #<?php echo esc_html($uid); ?> .cal-google-event:last-child { border-bottom: 0; }
        #<?php echo esc_html($uid); ?> .cal-google-event-title { font-weight: 600; margin-bottom: 4px; color: <?php echo esc_html($textColor); ?>; }
        #<?php echo esc_html($uid); ?> .cal-google-event-meta { color: <?php echo esc_html($textColor); ?>; font-size: 0.95em; }
        #<?php echo esc_html($uid); ?> .cal-google-event-description { margin-top: 6px; white-space: pre-line; color: <?php echo esc_html($textColor); ?>; }
        #<?php echo esc_html($uid); ?> .cal-google-empty { padding: 12px 14px; color: #777; font-style: italic; }
    </style>
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
                    <h3 class="cal-google-list-month-title"><?php echo esc_html($monthNames[$month] . ' ' . $year); ?></h3>
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
