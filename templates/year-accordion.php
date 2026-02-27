<div class="cal-google" id="<?php echo esc_attr($uid); ?>">
    <style>
        #<?php echo esc_html($uid); ?> .cal-google-month { border: 1px solid #d9d9d9; border-radius: 8px; margin: 0 0 10px; overflow: hidden; }
        #<?php echo esc_html($uid); ?> .cal-google-month { border-color: <?php echo esc_html($borderColor); ?>; }
        #<?php echo esc_html($uid); ?> .cal-google-month > summary { cursor: pointer; padding: 12px 14px; background: <?php echo esc_html($bgColor); ?>; font-weight: 600; color: <?php echo esc_html($textColor); ?>; }
        #<?php echo esc_html($uid); ?> .cal-google-month-content { padding: 10px 14px 14px; }
        #<?php echo esc_html($uid); ?> .cal-google-event { padding: 10px 0; border-bottom: 1px solid #ececec; }
        #<?php echo esc_html($uid); ?> .cal-google-event:last-child { border-bottom: 0; }
        #<?php echo esc_html($uid); ?> .cal-google-event-title { font-weight: 600; margin-bottom: 4px; color: <?php echo esc_html($textColor); ?>; }
        #<?php echo esc_html($uid); ?> .cal-google-event-meta { color: <?php echo esc_html($textColor); ?>; font-size: 0.95em; }
        #<?php echo esc_html($uid); ?> .cal-google-event-description { margin-top: 6px; white-space: pre-line; color: <?php echo esc_html($textColor); ?>; }
        #<?php echo esc_html($uid); ?> .cal-google-empty { color: #777; font-style: italic; }
    </style>
    <?php foreach ($monthsToShow as $month) : ?>
        <details class="cal-google-month" <?php echo $month === $currentMonth ? 'open' : ''; ?>>
            <summary><?php echo esc_html($monthNames[$month] . ' ' . $year); ?></summary>
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
