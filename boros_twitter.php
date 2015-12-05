<?php
/*
Plugin Name: Boros Twitter
Plugin URI: http://alexkoti.com
Description: Widget de Twitter simples. Permite a chamada direta por função.
Version: 1.0.0
Author: Alex Koti
Author URI: http://alexkoti.com
Dependencies: boros_extra_wp_functions/boros_extra_wp_functions.php
License: GPL2
*/

/**
 * TODO
 * - botão para resetar o cache
 * 
 * - template de saída opcional:
 * - - tags de substituição estilo %%TAGNAME%% ou {{TAGNAME}}
 * - - código BEFORE||AFTER
 * 
 * - melhorar a interface do widget, na opção de usar ou não a lista de seguidos em vez da user_timeline. Puxar as listas do user por ajax
 * 
 * - criar um modelo de saída com os layouts originais de cada usuário
 * 
 * - fazer um flush no cache transient ao salvar o widget
 * 
 */

class BorosTwitter {
	//@link http://www.problogdesign.com/wordpress/how-to-use-the-twitter-api-in-wordpress/
	
	/**
	 * @param type		: user|list|search
	 * @param user		: nome do usuário(publico)
	 * @param search		: string de busca, aceita hashtag
	 * @param list		: nome da lista
	 * @param number		: total de tweets para exibir
	 * @param include_rts	: true|false - incluir retweets
	 * @param avatars		: true|false - incluir avatares
	 * @param cache		: int - tempo em minutos do cache
	 * @param cache_unit	: int - unidade de tempo, em segundos, padrão 60(1 minuto)
	 * @param on_error		: return_empty|return_static_cache|return_errors
	 */
	var $defaults = array(
		'id' => '',
		'type' => 'user',
		'user' => 'twitter',
		'search' => '',
		'list' => '',
		'number' => 10,
		'include_rts' => false,
		'avatars' => false,
		'link_avatars' => false,
		'cache' => 60,
		'cache_unit' => 60,
		'title' => '',
		'show_title' => true,
		'show_actions' => true,
		'show_meta' => true,
		'on_error' => 'return_errors',
		'debug' => false,
	);
	var $config = array();
	var $twitter_config = array();
	var $json_url = '';
	var $transName = '';
	var $errors = array();
	var $tweets = array();
	
	function __construct( $args = array() ){
		$this->config = boros_parse_args( $this->defaults, $args );
		
		if( defined('WP_DEBUG') AND WP_DEBUG === true OR $this->config['debug'] == true ){
			error_reporting(E_ALL);
			ini_set('display_errors', 1);
		}
		
		/**
		 * Include twitter library
		 *
		 * @link https://github.com/J7mbo/twitter-api-php
		 * @link http://stackoverflow.com/questions/12916539/simplest-php-example-for-retrieving-user-timeline-with-twitter-api-version-1-1
		 */
		include_once('TwitterAPIExchange.php');
		/** Set access tokens here - see: https://dev.twitter.com/apps/ **/
		$this->twitter_config = array(
			'oauth_access_token'        => get_option('twitter_api_key_oauth_access_token'),  
			'oauth_access_token_secret' => get_option('twitter_api_key_oauth_access_token_secret'), 
			'consumer_key'              => get_option('twitter_api_key_consumer_key'),            
			'consumer_secret'           => get_option('twitter_api_key_consumer_secret'), 
		);
		
		// definir o tipo de lista
		switch( $this->config['type'] ){
			case 'search':
				if( $this->config['title'] == '' ){
					$search_string = urlencode( $this->config['search'] );
					$this->config['title'] = 'Hashtag %SEARCH%';
				}
				if( empty($this->config['search']) ){
					$this->errors['search'] = "Não foi definido um termo de busca";
				}
				else{
					$search_string = urlencode( $this->config['search'] );
					$cache_search = sanitize_title( $this->config['search'] );
					$this->json_url = "https://api.twitter.com/1.1/search/tweets.json";
					$this->fields = "?q={$search_string}&rpp={$this->config['number']}&lang=pt";
					$this->transName = "twitter_cache_search_{$cache_search}{$this->config['id']}";
				}
				break;
			
			case 'list':
				if( $this->config['title'] == '' )
					$this->config['title'] = 'Twitter %USER%';
				if( $this->config['user'] == 'twitter' or empty($this->config['user']) ){
					$this->errors['list'] = "Não foi definido um usuário para buscar a lista '{$this->config['list']}'";
				}
				else{
					$this->json_url = "https://api.twitter.com/1.1/lists/statuses.json";
					$this->fields = "?owner_screen_name={$this->config['user']}&slug={$this->config['list']}&count={$this->config['number']}";
					$this->transName = "twitter_cache_list_{$this->config['user']}_{$this->config['list']}{$this->config['id']}";
				}
				break;
			
			case 'user':
				if( $this->config['title'] == '' )
					$this->config['title'] = 'Twitter %USER%';
				$this->json_url = "https://api.twitter.com/1.1/statuses/user_timeline.json";
				$this->fields = "?screen_name={$this->config['user']}&include_rts={$this->config['include_rts']}&count={$this->config['number']}";
				$this->transName = "twitter_cache_user_{$this->config['user']}{$this->config['id']}";
				break;
			
			default:
				$this->errors['type'] = "Configuração 'type' incorreta.";
				break;
		}
		
		// não pegar o cache caso esteja em modo debug
		if( defined('WP_DEBUG') AND WP_DEBUG === true OR $this->config['debug'] == true ){
			$this->tweets == false;
		}
		// já temos o nome do transient(cache), podemos fazer uma verificação antes
		else{
			$this->tweets = get_transient($this->transName);
		}
		
		// não existe cache, fazer a requisição
		if( $this->tweets == false ){
			$this->get_tweets();
		}
	}
	
	/**
	 * Primeiro verifica erros, em caso negativo é feito o processamento dos resultados conforme o 'type'
	 * 
	 */
	function get_tweets(){
		//$getfield = "?screen_name={$this->config['user']}&count={$this->config['number']}";
		$getfield = $this->fields;
		$requestMethod = 'GET';
		$twitter = new TwitterAPIExchange($this->twitter_config);
		$json = $twitter->setGetfield($getfield)->buildOauth($this->json_url, $requestMethod)->performRequest();
		
		$twitter_data = json_decode($json, true);
		
		if( defined('WP_DEBUG') AND WP_DEBUG === true AND $this->config['debug'] == true ){
			pre($twitter_data, '$twitter_data');
		}
		
		// REFAZER TODAS AS VERIFICAÇÔES DE ERRO, NOT-FOUND, ETC
		if( isset($twitter_data['error']) ){
			$this->errors['api'] = $twitter_data['error'];
			
			if( $this->config['on_error'] == 'return_empty' ){
				return '';
			}
			else{
				$this->show_errors();
			}
			return;
		}
		
		$this->process_tweets( $twitter_data );
	}
	
	function process_tweets( $data ){
		if( $this->config['type'] == 'user' or $this->config['type'] == 'list' ){
			$this->process_user_tweets( $data );
		}
		if( $this->config['type'] == 'search' ){
			$this->process_search_tweets( $data );
		}
		
		if( !defined('WP_DEBUG') OR $this->config['debug'] == false AND !empty($this->tweets) ){
			// gravar o transiente dos resultados
			set_transient( $this->transName, $this->tweets, $this->config['cache_unit'] * $this->config['cache'] );
		}
	}
	
	function process_user_tweets( $data ){
		if( empty($data) ){
			return;
		}
		foreach( $data as $tweet ){
			// Need to get time in Unix format.
			$time = $tweet['created_at'];
			$time = date_parse($time);
			$uTime = mktime($time['hour'], $time['minute'], $time['second'], $time['month'], $time['day'], $time['year']);
			
			$image = ( isset($tweet['retweeted_status']) ) ? $tweet['retweeted_status']['user']['profile_image_url'] : $tweet['user']['profile_image_url'] ;
			// Now make the new array.
			$this->tweets[] = array(
				'user' 		=> $tweet['user']['screen_name'],
				'text' 		=> $tweet['text'],
				'status_id' => $tweet['id_str'],
				'name' 		=> $tweet['user']['name'],
				'permalink' => 'http://twitter.com/#!/'. $tweet['user']['name'] .'/status/'. $tweet['id_str'],
				'image' 	=> $image, /* Alternative image sizes method: http://dev.twitter.com/doc/get/users/profile_image/:screen_name */
				'time' 		=> $uTime,
				'source' 	=> $tweet['source'],
			);
		}
	}
	
	function process_search_tweets( $data ){
		//pre($data);
		foreach( $data['statuses'] as $tweet ){
			$time = $tweet['created_at'];
			$time = date_parse($time);
			$uTime = mktime($time['hour'], $time['minute'], $time['second'], $time['month'], $time['day'], $time['year']);
			
			$this->tweets[] = array(
				'user' 		=> $tweet['user']['screen_name'],
				'text' 		=> $tweet['text'],
				'status_id' => $tweet['id_str'],
				'name' 		=> $tweet['user']['id'],
				'permalink' => 'http://twitter.com/#!/'. $tweet['user']['id'] .'/status/'. $tweet['id_str'],
				'image' 	=> $tweet['user']['profile_image_url'],
				'time' 		=> $uTime,
				'source' 	=> $tweet['source'],
			);
		}
	}
	
	function output(){
		if( !isset($this->tweets) ){
			if( $this->config['on_error'] == 'return_empty' ){
				return '';
			}
		}
		
		if( !empty($this->errors) ){
			if( $this->config['on_error'] == 'return_errors' ){
				$this->show_errors();
			}
			elseif( $this->config['on_error'] == 'return_empty' ){
				return '';
			}
		}
		else{
			if( !empty($this->tweets) ){
				// debug
				if( defined('WP_DEBUG') AND WP_DEBUG === true OR $this->config['debug'] == true ){
					pre($this->tweets, '$this->tweets', false);
				}
			?>
			<div class="twitter_box">
				<?php
				if( $this->config['show_title'] == true ){
					$title = $this->config['title'];
					// user link
					$user_link = "<a href='http://twitter.com/{$this->config['user']}' target='_blank'>@{$this->config['user']}</a>";
					// search link
					$search_string = urlencode( $this->config['search'] );
					$search_link = "<a href='https://twitter.com/#!/search/{$search_string}' target='_blank'>{$this->config['search']}</a>";
					// replace
					$title = str_replace( '%USER%', $user_link, $title );
					$title = str_replace( '%SEARCH%', $search_link, $title );
					echo "<h2>{$title}</h2>";
				}
				?>
				<ul class="twitter">
					<?php
					foreach ( $this->tweets as $item ){
						$avatar = ( $this->config['avatars'] == false ) ? '' : "<img src='{$item['image']}' alt='{$item['user']}' title='{$item['user']}' class='twitter-avatar' /> ";
						$user_link = ( $this->config['link_avatars'] == false ) ? "{$avatar}" : "<a href='http://twitter.com/{$item['user']}'>{$avatar} <span class='twitter-user_name'>{$item['user']}</span></a>";
					?>
					<li>
						<div class="tweet-user"><?php echo $user_link; ?></div>
						<div class="tweet-column">
							<div class="tweet-text"><?php echo twitter_links( $item['text'] ); ?></div>
							<?php if( $this->config['show_meta'] == true ){ ?>
							<div class="tweet-meta">
								<span class="tweet-source">via <?php echo html_entity_decode($item['source']); ?>,</span> 
								<span class="tweet-date">
									<?php
										date_default_timezone_set('America/Sao_Paulo');
										$tweet_date = $item['time'];
										if ( ( abs( time() - $tweet_date) ) < 86400 ){
											$h_time = sprintf( __('%s ago'), human_time_diff( $tweet_date ) );
										}
										else{
											//$h_time = date_i18n(__('j \d\e F \d\e Y'), $tweet_date);
											$h_time = date_i18n(__('d\/m\/Y'), $tweet_date);
										}
										echo sprintf( __('%s', 'twitter-for-wordpress'),'<span class="twitter-timestamp"><abbr title="' . date(__('Y/m/d H:i:s'), $item['time']) . '">' . $h_time . '</abbr></span>' );
									?>
								</span>
							</div>
							<?php } ?>
							<?php if( $this->config['show_actions'] == true ){ ?>
							<div class="tweet-actions">
								<a href="http://twitter.com/?status=@<?php echo $item['user'] ?>%20&amp;in_reply_to_status_id=<?php echo $item['status_id'] ?>&amp;in_reply_to=<?php echo $item['user'] ?>" class="twitter-reply" target="_blank">responder</a> 
								 &middot; 
								<a href="<?php echo $item['permalink']; ?>" class="twitter-view" target="_blank">ver tweet</a>
							</div>
							<?php } ?>
						</div>
					</li>
					<?php } ?>
				</ul><!-- .twitter -->
			</div><!-- .twitter_box -->
			<?php
			}
			else{
				echo '<p>Sem resultados.</p>';
			}
		}
	}
	
	function show_errors(){
		echo '<dl class="twitter_errors">';
		foreach( $this->errors as $key => $val ){
			echo "<dt>{$key}</dt>";
			echo "<dd>";
			echo $val;
			echo "</dd>";
		}
		echo '</dl>';
	}
}

function boros_twitter( $args ){
	$tweets = new BorosTwitter($args);
	$tweets->output();
}



/**
 * Configurações de cache
 * do_not_cache_feeds() 			- não utilizar cache com debug ativado
 * wp_feed_cache_transient_lifetime 	- mudar o tempo de cache para 30 minutos
 */
if ( defined('WP_DEBUG') && WP_DEBUG ){
	function do_not_cache_feeds(&$feed) {
		$feed->enable_cache(false);
	}
	add_action( 'wp_feed_options', 'do_not_cache_feeds' );
	add_filter( 'wp_feed_cache_transient_lifetime', create_function( '$a', 'return 1;' ) );
}



/**
 * Auxiliar: criar links com base nos patterns:
 * 	- links
 * 	- @user
 * 	- user
 * 	- hashtags
 */
function twitter_links( $text ) {
	// Props to Allen Shaw & webmancers.com
	
	// match protocol://address/path/file.extension?some=variable&another=asf%
	//$text = preg_replace("/\b([a-zA-Z]+:\/\/[a-z][a-z0-9\_\.\-]*[a-z]{2,6}[a-zA-Z0-9\/\*\-\?\&\%]*)\b/i","<a href=\"$1\" class=\"twitter-link\">$1</a>", $text);
	$text = preg_replace('/\b([a-zA-Z]+:\/\/[\w_.\-]+\.[a-zA-Z]{2,6}[\/\w\-~.?=&%#+$*!]*)\b/i',"<a href=\"$1\" class=\"twitter-link\">$1</a>", $text);
	
	// match www.something.domain/path/file.extension?some=variable&another=asf%
	//$text = preg_replace("/\b(www\.[a-z][a-z0-9\_\.\-]*[a-z]{2,6}[a-zA-Z0-9\/\*\-\?\&\%]*)\b/i","<a href=\"http://$1\" class=\"twitter-link\">$1</a>", $text);
	$text = preg_replace('/\b(?<!:\/\/)(www\.[\w_.\-]+\.[a-zA-Z]{2,6}[\/\w\-~.?=&%#+$*!]*)\b/i',"<a href=\"http://$1\" class=\"twitter-link\">$1</a>", $text);    

	// match name@address
	$text = preg_replace("/\b([a-zA-Z][a-zA-Z0-9\_\.\-]*[a-zA-Z]*\@[a-zA-Z][a-zA-Z0-9\_\.\-]*[a-zA-Z]{2,6})\b/i","<a href=\"mailto://$1\" class=\"twitter-email-link\">$1</a>", $text);
	
	//mach #trendingtopics. Props to Michael Voigt
	//$text = preg_replace('/([\.|\,|\:|\¡|\¿|\>|\{|\(]?)#{1}(\w*)([\.|\,|\:|\!|\?|\>|\}|\)]?)\s/i', "$1<a href=\"http://twitter.com/#search?q=$2\" class=\"twitter-trend-link\">#$2</a>$3 ", $text);
	//$text = preg_replace('/(^|\s)#(\w+)/', '\1<a href="http://search.twitter.com/search?q=%23\2">#\2</a>', $text);
	// @link http://stackoverflow.com/a/8455815/679195
	$text = preg_replace( '/\s*([#]\S+|[^\\x00-\\xff])\s*/u', ' <a href="http://search.twitter.com/search?q=%23$1">$1</a> ', $text);
	
	//match users
	$text = preg_replace('/([\.|\,|\:|\¡|\¿|\>|\{|\(]?)@{1}(\w*)([\.|\,|\:|\!|\?|\>|\}|\)]?)\s/i', "$1<a href=\"http://twitter.com/$2\" class=\"twitter-user\">@$2</a>$3 ", $text);
	return $text;
}


add_action( 'widgets_init', create_function( '', 'register_widget("boros_widget_twitter");' ) );
class boros_widget_twitter extends WP_Widget {
	
	function __construct(){
		parent::__construct(
			'boros_widget_twitter', // Base ID
			'Twitter', // Name
			array( 'description' =>'Widget de Twitter', ) // Args
		);
		
		//// opções do widget que será aplicado no frontend
		//$widget_ops = array(
		//	'classname' => 'boros_widget_twitter', 
		//	'description' => 'Widget de Twitter', 
		//	'width' => 600,
		//);
		//
		//// opções do controle
		//$control_ops = array();
		///** MODELO
		//$control_ops = array(
		//	'width' => 300,
		//	'height' => 350,
		//	'id_base' => 'sidebar_home'
		//);/**/
		//
		//// registrar o widget
		//$this->WP_Widget( 'boros_widget_twitter', 'Widget de Twitter', $widget_ops, $control_ops );
	}
	
	function boros_widget_twitter(){
		// opções do widget que será aplicado no frontend
		$widget_ops = array(
			'classname' => 'boros_widget_twitter', 
			'description' => 'Widget de Twitter', 
			'width' => 600,
		);
		
		// opções do controle
		$control_ops = array();
		/** MODELO
		$control_ops = array(
			'width' => 300,
			'height' => 350,
			'id_base' => 'sidebar_home'
		);/**/
		
		// registrar o widget
		$this->WP_Widget( 'boros_widget_twitter', 'Widget de Twitter', $widget_ops, $control_ops );
	}
	
	function widget($args, $instance){
		extract($args);
		
		echo $before_widget;
		if( isset($instance['active']) )
			boros_twitter( $instance );
		else
			echo '<div class="twitter_box"><p class="txt_error">Twitter widget desabilitado.</p></div>';
		echo $after_widget;
	}
	
	function form($instance){
		// sempre limpar os valores vazios
		$instance = array_filter($instance);
		// defaults
		$defaults = array(
			'id' => '',
			'type' => 'user',
			'user' => '',
			'search' => '',
			'list' => '',
			'number' => 10,
			'include_rts' => false,
			'avatars' => false,
			'link_avatars' => false,
			'cache' => 60,
			'title' => '',
			'active' => false,
			'debug' => false,
		);
		// mesclar dados
		$instance = wp_parse_args( (array) $instance, $defaults );
		?>
			<p>
				<label for="<?php echo $this->get_field_id('title'); ?>">Título:</label>
				<input type="text" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" value="<?php echo $instance['title']; ?>" class="widefat" /><br />
				<small>Tags: <code>%USER%</code>, <code>%SEARCH%</code></small>
			</p>
			<p>
				Tipo: <br />
				<label><input type="radio" name="<?php echo $this->get_field_name('type'); ?>" value="user" <?php checked($instance['type'], 'user'); ?> />
				<label>Usuário</label><br />
				<label><input type="radio" name="<?php echo $this->get_field_name('type'); ?>" value="list" <?php checked($instance['type'], 'list'); ?> />
				Lista de usuário</label><br />
				<label><input type="radio" name="<?php echo $this->get_field_name('type'); ?>" value="search" <?php checked($instance['type'], 'search'); ?> />
				Busca</label>
			</p>
			<p>
				<label for="<?php echo $this->get_field_id('user'); ?>">Usuário:</label>
				<input type="text" id="<?php echo $this->get_field_id('user'); ?>" name="<?php echo $this->get_field_name('user'); ?>" value="<?php echo $instance['user']; ?>" class="widefat" />
			</p>
			<p>
				<label for="<?php echo $this->get_field_id('list'); ?>">Lista:</label>
				<input type="text" id="<?php echo $this->get_field_id('list'); ?>" name="<?php echo $this->get_field_name('list'); ?>" value="<?php echo $instance['list']; ?>" class="widefat" />
			</p>
			<p>
				<label for="<?php echo $this->get_field_id('search'); ?>">Busca:</label>
				<input type="text" id="<?php echo $this->get_field_id('search'); ?>" name="<?php echo $this->get_field_name('search'); ?>" value="<?php echo $instance['search']; ?>" class="widefat" />
			</p>
			<p>
				<span class="inline_block">
					<label for="<?php echo $this->get_field_id('number'); ?>">Quantidade:</label>
					<input type="text" id="<?php echo $this->get_field_id('number'); ?>" name="<?php echo $this->get_field_name('number'); ?>" value="<?php echo $instance['number']; ?>" class="iptw_30" />
				</span>
				<span class="inline_block">
					<label for="<?php echo $this->get_field_id('include_rts'); ?>">
						<input type="checkbox" id="<?php echo $this->get_field_id('include_rts'); ?>" name="<?php echo $this->get_field_name('include_rts'); ?>" value="1" <?php checked($instance['include_rts']); ?> /> 
						Incluir retweets
					</label>
				</span>
			</p>
			<p>
				<label for="<?php echo $this->get_field_id('cache'); ?>">Cache(em minutos):</label>
				<input type="text" id="<?php echo $this->get_field_id('cache'); ?>" name="<?php echo $this->get_field_name('cache'); ?>" value="<?php echo $instance['cache']; ?>" class="iptw_30" />
			</p>
			<p>
				<label for="<?php echo $this->get_field_id('avatars'); ?>">
					<input type="checkbox" id="<?php echo $this->get_field_id('avatars'); ?>" name="<?php echo $this->get_field_name('avatars'); ?>" value="1" <?php checked($instance['avatars']); ?> /> 
					Mostrar avatares
				</label><br />
				<label for="<?php echo $this->get_field_id('link_avatars'); ?>">
					<input type="checkbox" id="<?php echo $this->get_field_id('link_avatars'); ?>" name="<?php echo $this->get_field_name('link_avatars'); ?>" value="1" <?php checked($instance['link_avatars']); ?> /> 
					Linkar avatares
				</label>
			</p>
			<p>
				<label for="<?php echo $this->get_field_id('active'); ?>">
					<input type="checkbox" id="<?php echo $this->get_field_id('active'); ?>" name="<?php echo $this->get_field_name('active'); ?>" value="1" <?php checked($instance['active']); ?> /> 
					Habilitado (desmarque para desativar temporariamente)
				</label>
			</p>
			<p>
				<label for="<?php echo $this->get_field_id('id'); ?>">ID <small>(usar apenas para diferenciar entre widgets de sidebars diferentes)</small>:</label>
				<input type="text" id="<?php echo $this->get_field_id('id'); ?>" name="<?php echo $this->get_field_name('id'); ?>" value="<?php echo $instance['id']; ?>" class="widefat" />
			</p>
			<p>
				<label for="<?php echo $this->get_field_id('debug'); ?>">
					<input type="checkbox" id="<?php echo $this->get_field_id('debug'); ?>" name="<?php echo $this->get_field_name('debug'); ?>" value="1" <?php checked($instance['debug']); ?> /> 
					Debug (marcar para conferir informações recuperadas e bloquear o cache)
				</label>
			</p>
		<?php
	}
	
	function update( $new_instance, $old_instance ) {
		$instance = $old_instance;
		$instance['id'] = $new_instance['id'];
		$instance['type'] = $new_instance['type'];
		$instance['user'] = $new_instance['user'];
		$instance['list'] = $new_instance['list'];
		$instance['search'] = $new_instance['search'];
		$instance['number'] = empty($new_instance['number']) ? 10 : $new_instance['number'];
		$instance['include_rts'] = $new_instance['include_rts'];
		$instance['cache'] = $new_instance['cache'];
		$instance['avatars'] = $new_instance['avatars'];
		$instance['link_avatars'] = $new_instance['link_avatars'];
		$instance['title'] = $new_instance['title'];
		$instance['active'] = $new_instance['active'];
		$instance['debug'] = $new_instance['debug'];
		return $instance;
	}
}


