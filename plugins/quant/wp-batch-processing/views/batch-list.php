<div class="wrap">
    <h2><?php _e( 'Batches', 'quant-wp-batch-processing' ); ?></a></h2>
    <p><a class="button" href="<?php echo admin_url('admin.php?page=dg-batches&action=view&id=all'); ?>">Process all</a></p>
    <form method="post">
        <input type="hidden" name="page" value="batch_runner_list_table">
        <?php
        $list_table = new Quant_WP_BP_List_Table();
        $list_table->prepare_items();
        $list_table->display();
        ?>
    </form>
</div>