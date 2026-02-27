<article class="cal-google-event">
    <div class="cal-google-event-title"><?php echo esc_html($event['summary'] !== '' ? $event['summary'] : $event['summary_fallback']); ?></div>
    <div class="cal-google-event-meta"><?php echo esc_html($event['when']); ?></div>
    <?php if ($event['location'] !== '') : ?>
        <div class="cal-google-event-meta"><?php echo esc_html($event['location_label']) . esc_html($event['location']); ?></div>
    <?php endif; ?>
    <?php if ($event['description'] !== '') : ?>
        <div class="cal-google-event-description"><?php echo esc_html($event['description']); ?></div>
    <?php endif; ?>
    <?php if ($event['event_url'] !== '') : ?>
        <div class="cal-google-event-description"><a href="<?php echo esc_url($event['event_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($event['event_link_label']); ?></a></div>
    <?php endif; ?>
    <?php if ($event['google_template_url'] !== '') : ?>
        <div class="cal-google-event-description"><a href="<?php echo esc_url($event['google_template_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($event['google_template_link_label']); ?></a></div>
    <?php endif; ?>
    <?php if ($event['ics_download_url'] !== '') : ?>
        <div class="cal-google-event-description"><a href="<?php echo esc_url($event['ics_download_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($event['download_ics_label']); ?></a></div>
    <?php endif; ?>
</article>
