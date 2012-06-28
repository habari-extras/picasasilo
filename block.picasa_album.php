<?php if(count($post->picasa_images)):
foreach($post->picasa_images as $image): ?>
<a href="<?php echo $image['picasa_url']; ?>"><img src="<?php echo $image['url']; ?>" alt="<?php echo $image['title']; ?>"></a>
<?php endforeach; endif; ?>