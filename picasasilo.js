picasagallery = function(){};

function start_picasagallery()
{
	$.post(picasagallery.url,{'album': picasagallery.album},process_picasagallery,'json');
}

function process_picasagallery(data)
{
	habari.editor.insertSelection(data);
}

habari.media.output.picasa = 
				{
				  insert_image: function(fileindex, fileobj)
					{
						habari.editor.insertSelection('<a href="' + fileobj.picasa_url  + '"><img class="picasaimg" src="' + fileobj.url + '" alt="' + fileobj.description + '"/></a>');
					}
				}

        habari.media.preview.picasa = function(fileindex, fileobj) 
				{
					//this does not work yet!
					var stats = '';
					var out = '';

					// CRAP
					// out += '<a href="#" onclick="habari.media.showdir(\'Picasa/photos/' + fileobj.picasa_id[0]  + '\'); return false;">';
					
					out += '<div class="mediatitle">' + fileobj.title + '</div>';
					out += '<div class="mediatitle">' + fileobj.description + '</div>';
					out += '<img src="' + fileobj.thumbnail_url + '" /><div class="mediastats"> ' + stats + '</div>';
					out += '</a>';
					return out;
				}