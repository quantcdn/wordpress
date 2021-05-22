<?php

namespace Quant;

class Field
{
    /**
     * Render a url input field
     *
     * @param array $args
     * @return void
     */
    public static function url($args = [])
    {
        ?><div>
            <input type="url" class="regular-text" name="<?= esc_attr($args['name']); ?>" placeholder="<?= esc_url($args['placeholder']); ?>" value="<?= esc_url($args['value']); ?>" required>
            <?= !empty($args['description']) ? "<p class=\"description\">{$args['description']}</p>" : ''; ?>
        </div><?php
    }

    /**
     * Render a text input field
     *
     * @param array $args
     * @return void
     */
    public static function text($args = [])
    {
        ?><div>
            <input type="text" class="regular-text" name="<?= esc_attr($args['name']); ?>" placeholder="<?= esc_attr($args['placeholder']) ?>" value="<?= esc_attr($args['value']) ?>" >
            <?= !empty($args['description']) ? "<p class=\"description\">{$args['description']}</p>" : ''; ?>
        </div><?php
    }


    /**
     * Render a password input field
     *
     * @param array $args
     * @return void
     */
    public static function password($args = [])
    {
        ?><div>
            <input type="password" class="regular-text" name="<?= esc_attr($args['name']); ?>" placeholder="<?= esc_attr($args['placeholder']) ?>" value="<?= esc_attr($args['value']) ?>" >
            <?= !empty($args['description']) ? "<p class=\"description\">{$args['description']}</p>" : ''; ?>
        </div><?php
    }

    /**
     * Render a textarea input field
     *
     * @param array $args
     * @return void
     */
    public static function textarea($args = [])
    {
        ?><div>
            <textarea class="regular-text" rows="8" name="<?= esc_attr($args['name']); ?>" placeholder="<?= esc_attr($args['placeholder']) ?>"><?= esc_attr($args['value']) ?></textarea>
            <?= !empty($args['description']) ? "<p class=\"description\">{$args['description']}</p>" : ''; ?>
        </div><?php
    }

    /**
     * Render a checkbox input field
     */
    public static function checkbox($args = []) {

        ?><div>
            <input type="checkbox" id="<?= esc_attr($args['name']); ?>" value="1" name="<?= esc_attr($args['name']); ?>" <?= checked(1, esc_attr($args['value']), false)  ?>>
            <label for="<?= esc_attr($args['name']); ?>"><?= !empty($args['description']) ? "{$args['description']}" : ''; ?></label>
        </div><?php

    }


}
