<?php
/**
 * Partial: current consent status and withdrawal.
 *
 * Hidden until consent.js (W5) has a valid choice to show. consent.js fills
 * [data-field="status"|"date"|"id"] from the trackwp_consent cookie via the
 * reader, removes the hidden attribute, and handles data-action="withdraw".
 *
 * Expects: $texts.
 */
defined('ABSPATH') || exit;
?>
<section class="trackwp-consent__status" data-role="consent-status" hidden aria-live="polite" aria-label="<?php esc_attr_e('Dit nuværende samtykke', 'trackwp'); ?>">
    <h3 class="trackwp-consent__status-heading"><?php esc_html_e('Dit nuværende samtykke', 'trackwp'); ?></h3>
    <dl class="trackwp-consent__status-list">
        <dt><?php esc_html_e('Status', 'trackwp'); ?></dt>
        <dd data-field="status"></dd>
        <dt><?php esc_html_e('Givet den', 'trackwp'); ?></dt>
        <dd data-field="date"></dd>
        <dt><?php esc_html_e('Samtykke-ID', 'trackwp'); ?></dt>
        <dd data-field="id"></dd>
    </dl>
    <button type="button" class="trackwp-consent__btn trackwp-consent__btn--withdraw" data-action="withdraw">
        <?php echo esc_html($texts['withdraw_button']); ?>
    </button>
</section>
