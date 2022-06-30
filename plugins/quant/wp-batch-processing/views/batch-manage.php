<?php
/* @var mixed $id - The batch id */
if ($id == 'all') {
	$count = 0;
	$processed = 0;
	$percentage = 0;
	$finished = true;
	$title = "All items";
	$batches = Quant_WP_Batch_Processor::get_instance()->get_batches();

	foreach ( $batches as $batch ) {
		$count += $batch->get_items_count();
		$processed += $batch->get_processed_count();

		if (!$batch->is_finished()) {
			$finished = false;
		}
	}

	$percentage  = round(($processed / $count) * 100, 2);
}
else {
	$batch = Quant_WP_Batch_Processor::get_instance()->get_batch($id);
	if(is_null($batch)) {
		echo 'Batch not found.';
		return;
	}

	$count = $batch->get_items_count();
	$processed = $batch->get_processed_count();
	$percentage = $batch->get_percentage();
	$finished = $batch->is_finished();
	$title = $batch->title;
}

?>

<h1><?php echo $title; ?></a></h1>

<div class="batch-process">
	<div class="batch-process-main">
		<ul class="batch-process-stats">
			<li><strong>Total:</strong> <span id="batch-process-total"><?php echo $count; ?></span></li>
			<li><strong>Processed:</strong> <span id="batch-process-processed"><?php echo $processed; ?></span> <span id="batch-process-percentage">(<?php echo $percentage; ?>%)</span></li>
		</ul>
		<div class="batch-process-progress-bar">
			<?php
			$style = $percentage > 0 ? 'width:'.$percentage.'%' : '';
			?>
			<div class="batch-process-progress-bar-inner" style="<?php echo $style; ?>"></div>
		</div>
		<div class="batch-process-current-item">

		</div>
	</div>
	<div class="batch-process-actions">
		<?php if(!$finished): ?>
			<button class="button-primary" id="batch-process-start">Start</button>
			<button class="button" id="batch-process-stop">Stop</button>
			<button class="button" id="batch-process-restart">Restart</button>
		<?php else: ?>
			<button class="button-primary" id="batch-process-restart">Restart</button>
		<?php endif; ?>
	</div>
</div>

<div id="batch-errors" style="display: none;">
	<h3>List of errors</h3>
	<ol id="batch-errors-list">

	</ol>
</div>


<style type="text/css">
.batch-process-stats {
	text-align:center;
	list-style: none;
}
.batch-process-stats li {
	display: inline-block;
	min-width: 20%;
}
.batch-process-progress-bar-inner {
    position: absolute;
    background: #0073aa;
    width: 0;
    height: 100%;
    left: 0;
}
.batch-process-progress-bar {
    background: #f0f0f0;
    min-height: 20px;
    position: relative;
}
.batch-process-actions {
    padding: 10px;
    text-align:center;
}
.batch-process-current-item {
	text-align: center;
}
.batch-process-main {
    padding: 10px;
    border-bottom: 1px solid #f0f0f0;
}
.batch-process {
    max-width: 500px;
    background: #fff;
    margin-top: 15px;
}
.batch-process-current-item {
    padding-top: 5px;
    padding-bottom: 5px;
    min-height: 12px;
}

</style>