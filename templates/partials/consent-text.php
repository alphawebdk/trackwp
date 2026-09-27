<?php
/**
 * Partial: first-layer banner text (controller, purposes, data types,
 * sharing, ACM sentence, withdrawal) as separate paragraphs.
 *
 * Expects: $texts (TrackWP_Consent_Profile::banner_texts()).
 * Optional: $trackwp_text_id  id of the wrapper (for aria-describedby).
 *           $trackwp_privacy  bool, render the privacy policy link (needs $config['privacy_url']).
 */
defined('ABSPATH') || exit;

$trackwp_blocks = isset($texts['blocks']) && is_array($texts['blocks']) ? $texts['blocks'] : array();
?>
<div class="trackwp-consent__description"<?php echo !empty($trackwp_text_id) ? ' id="' . esc_attr($trackwp_text_id) . '"' : ''; ?>>
    <?php foreach ($trackwp_blocks as $trackwp_block) : ?>
        <p><?php echo esc_html($trackwp_block); ?></p>
    <?php endforeach; ?>
    <?php if (!empty($trackwp_privacy) && !empty($config['privacy_url'])) : ?>
        <p><a class="trackwp-consent__link" href="<?php echo esc_url($config['privacy_url']); ?>"><?php esc_html_e('Læs vores privatlivspolitik', 'trackwp'); ?></a></p>
    <?php endif; ?>
</div>
<?php
unset($trackwp_blocks, $trackwp_block);
