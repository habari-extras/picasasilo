<?php
foreach($theme->tvi_picasa_photos as $photo): ?>
	<p><a href="<?php echo $photo['picasa_url']; ?>"><img src="<?php echo $photo['url']; ?>"></a></p>
<?php endforeach; ?>
<a href="<?php echo $theme->tvi_picasa_albumlink; ?>"><?php _e("View all photos"); ?></a>