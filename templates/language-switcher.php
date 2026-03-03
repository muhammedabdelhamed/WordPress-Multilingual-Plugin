<?php
/**
 * Language Switcher Template.
 *
 * This template can be overridden by placing a copy in your theme at:
 *   yourtheme/maharat/language-switcher.php
 *
 * Available variables:
 *   $items           array  List of languages with: code, name, native_name, flag_url, url, is_current
 *   $switcher_style  string Style: 'dropdown', 'list', 'inline', 'flags'
 *   $show_flags      bool   Whether to show flag images
 *   $show_names      bool   Whether to show language names
 *   $show_native     bool   Whether to show native language names
 *   $current_lang    string Current language code
 *
 * @package Maharat\Multilingual
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $items ) ) {
    return;
}

// Find the current language item.
$current_item = null;
foreach ( $items as $item ) {
    if ( $item['is_current'] ) {
        $current_item = $item;
        break;
    }
}

$wrapper_class = 'maharat-switcher maharat-switcher--' . esc_attr( $switcher_style );
?>

<div class="<?php echo esc_attr( $wrapper_class ); ?>" data-maharat-switcher>

    <?php if ( 'dropdown' === $switcher_style && $current_item ) : ?>
        <button type="button" class="maharat-switcher__current"
                aria-expanded="false"
                aria-haspopup="listbox"
                aria-label="<?php esc_attr_e( 'Select language', 'maharat-multilingual' ); ?>">
            <?php if ( $show_flags && ! empty( $current_item['flag_url'] ) ) : ?>
                <img src="<?php echo esc_url( $current_item['flag_url'] ); ?>"
                     alt="<?php echo esc_attr( $current_item['name'] ); ?>"
                     class="maharat-flag"
                     width="24" height="16"
                     loading="lazy">
            <?php endif; ?>
            <?php if ( $show_names || $show_native ) : ?>
                <span class="maharat-switcher__label">
                    <?php
                    if ( $show_native && ! empty( $current_item['native_name'] ) ) {
                        echo esc_html( $current_item['native_name'] );
                    } elseif ( $show_names ) {
                        echo esc_html( $current_item['name'] );
                    }
                    ?>
                </span>
            <?php endif; ?>
        </button>
    <?php endif; ?>

    <ul class="maharat-switcher__list" role="listbox" aria-label="<?php esc_attr_e( 'Languages', 'maharat-multilingual' ); ?>">
        <?php foreach ( $items as $item ) :
            $li_class = 'maharat-switcher__item';
            if ( $item['is_current'] ) {
                $li_class .= ' maharat-switcher__item--current';
            }
        ?>
            <li class="<?php echo esc_attr( $li_class ); ?>" role="option"
                aria-selected="<?php echo $item['is_current'] ? 'true' : 'false'; ?>">
                <a href="<?php echo esc_url( $item['url'] ); ?>"
                   hreflang="<?php echo esc_attr( $item['code'] ); ?>"
                   lang="<?php echo esc_attr( $item['code'] ); ?>">

                    <?php if ( $show_flags && ! empty( $item['flag_url'] ) ) : ?>
                        <img src="<?php echo esc_url( $item['flag_url'] ); ?>"
                             alt="<?php echo esc_attr( $item['name'] ); ?>"
                             class="maharat-flag"
                             width="24" height="16"
                             loading="lazy">
                    <?php endif; ?>

                    <?php if ( 'flags' !== $switcher_style ) : ?>
                        <?php if ( $show_native && ! empty( $item['native_name'] ) ) : ?>
                            <span class="maharat-switcher__native"><?php echo esc_html( $item['native_name'] ); ?></span>
                        <?php endif; ?>
                        <?php if ( $show_names && $show_native && $item['name'] !== $item['native_name'] ) : ?>
                            <span class="maharat-switcher__english">(<?php echo esc_html( $item['name'] ); ?>)</span>
                        <?php elseif ( $show_names && ! $show_native ) : ?>
                            <span class="maharat-switcher__english"><?php echo esc_html( $item['name'] ); ?></span>
                        <?php endif; ?>
                    <?php endif; ?>

                </a>
            </li>
        <?php endforeach; ?>
    </ul>

</div>
