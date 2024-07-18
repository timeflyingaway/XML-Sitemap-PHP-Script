<?php
/**
 * XML Sitemap PHP Script
 * For more info, see: https://github.com/jdevalk/XML-Sitemap-PHP-Script
 * Copyright (C), 2011 - 2022 - Joost de Valk, joost@joost.blog
 * Copyright (C), 2024        - TimeFlyingAway, https://github.com/TimeFlyingAway
 */

class Joost_XML_Sitemap_PHP {
	const CONFIG_FILE = 'xml-sitemap-config.ini';

	/**
	 * Holds the output.
	 */
	private string $output = '';

	/**
	 * Holds the path.
	 */
	private string $path;

	/**
	 * Holds the base URL.
	 */
	private string $url;

	/**
	 * Array of filetypes we're adding to the XML sitemap.
	 */
	private array $filetypes;

	/**
	 * Relative path to the XSL file.
	 */
	private string $xsl = 'xml-sitemap.xsl';

	/**
	 * Files we ignore in our output.
	 */
	private array $ignore = [];

	/**
	 * The change frequency of the files.
	 */
	private string $changefreq = '';

	/**
	 * The priority of the file, between 0.1 and 1.
	 */
	private float $priority = 0;

	/**
	 * Determines if we recurse directories or not.
	 */
	private bool $recursive = true;

	/**
	 * URLs we replace with something else.
	 */
	private array $replacements;

	/**
	 * URLs we replace with latest modified time.
	 */
	private array $replacelatestmod;

	/**
	 * Last modified date of all files.
	 */
	private int $last_mod_date = 0;

	/**
	 * The Cache-Control Header.
	 */
	private string $cache_control;

	/**
	 * The expire time used in header.
	 */
	private int $expire_time;

	/**
	 * The expire time used in header.
	 */
	private bool $localcache;

	/**
	 * The expire time used in header.
	 */
	private string $localcache_file;
	
	/**
	 * The expire time used in header.
	 */
	private int $localcache_expire;

	/**
	 * Generates our XML sitemap.
	 *
	 * @return void
	 */
	public function generate(): void {
		$this->read_ini();
		if ( ! $this->use_cache() ) {
			$this->read_dir();
			$this->output();
		}
		$this->set_headers();
	}

	/**
	 * Read our ini file.
	 *
	 * @return void
	 */
	private function read_ini(): void {
		if ( file_exists( './' . self::CONFIG_FILE ) ) {
			$config = parse_ini_file( './' . self::CONFIG_FILE );
		} elseif ( file_exists( '../' . self::CONFIG_FILE ) ) {
			$config = parse_ini_file( '../' . self::CONFIG_FILE );
		} else {
			printf( 'Error: unable to load %1$s, please copy xml-sitemap-config-sample.ini to %1$s and adjust.' . PHP_EOL, self::CONFIG_FILE );
			die( 1 );
		}

		$this->changefreq   = (string) $config['changefreq'];
		$this->path         = (string) $config['directory'];
		$this->url          = (string) $config['directory_url'];
		$this->filetypes    = (array) $config['filetypes'];
		$this->ignore       = array_merge( $config['ignore'], [ '.', '..' ] );
		$this->priority     = (float) $config['priority'];
		$this->recursive    = (bool) $config['recursive'];
		$this->replacements = (array) $config['replacements'];
		$this->replacelatestmod = (array) $config['replacelatestmod'];
		$this->xsl          = (string) $config['xsl'];
		$this->cache_control = (string) $config['cache_control'];
		$this->expire_time  = (int) $config['expire_time'];
		$this->localcache   = (bool) $config['localcache'];
		$this->localcache_file = (string) $config['localcache_file'];
		$this->localcache_expire = (int) $config['localcache_expire'];

		// Use HTTP_HOST from the request if empty.
		if ( empty( $this->url ) ) {
			$protocol = ( ! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443 ) ? "https://" : "http://";
			$host = $_SERVER['HTTP_HOST'];
			$this->url = $protocol . $host . '/';
		}
	}

	/**
	 * Kick off the script reading the dir provided in config.
	 *
	 * @return void
	 */
	private function read_dir(): void {
		$this->parse_dir( $this->path, $this->url );
	}

	/**
	 * Reads a directory and adds files found to the output.
	 *
	 * @param string $dir The directory to read.
	 * @param string $url The base URL.
	 *
	 * @return void
	 */
	private function parse_dir( string $dir, string $url ): void {
		$handle = opendir( $dir );

		while ( false !== ( $file = readdir( $handle ) ) ) {
			$directory = $dir . $file;

			// Check if this file needs to be ignored, if so, skip it.
			if ( in_array( iconv('ISO-8859-1', 'UTF-8//IGNORE', $directory ), $this->ignore ) || in_array( iconv('ISO-8859-1', 'UTF-8//IGNORE', $file ), $this->ignore ) ) {
				continue;
			}

			if ( $this->recursive && is_dir( $directory ) ) {
				$this->parse_dir( $directory . '/', $url . $file . '/' );
			}

			// Create a W3C valid date for use in the XML sitemap based on the file modification time.
			$file_mod_time = filemtime( $directory );
			if ( ! $file_mod_time ) {
				$file_mod_time = filectime( $directory );
			}

			if ( $file_mod_time > $this->last_mod_date ) {
				$this->last_mod_date = $file_mod_time;
			}

			// Check whether the file has on of the extensions allowed for this XML sitemap.
			$extension = pathinfo( $directory, PATHINFO_EXTENSION );
			if ( empty( $extension ) || ! in_array( $extension, $this->filetypes ) ) {
				continue;
			}

			if ( in_array( iconv('ISO-8859-1', 'UTF-8//IGNORE', $directory ), $this->replacelatestmod ) || in_array( iconv('ISO-8859-1', 'UTF-8//IGNORE', $file ), $this->replacelatestmod ) ) {
				$mod = '${last_mod_date}';
			} else {
				$mod = date( 'c', $file_mod_time );
			}

			// Replace the file with its replacement from the settings, if needed.
			if ( isset( $this->replacements[ $file ] ) ) {
				$file = $this->replacements[ $file ];
			} else if ( isset( $this->replacements[ $directory ] ) ) {
				$file = $this->replacements[ $directory ];
			}

			// Start creating the output
			$output = '<url>' . PHP_EOL;
			$output .= "\t" . '<loc>' . $url . rawurlencode( $file ) . '</loc>' . PHP_EOL;
			$output .= "\t" . '<lastmod>' . $mod . '</lastmod>' . PHP_EOL;
			if ( ! empty( $this->changefreq ) ) {
				$output .= "\t" . '<changefreq>' . $this->changefreq . '</changefreq>' . PHP_EOL;
			}
			if ( $this->priority > 0 && $this->priority <= 1 ) {
				$output .= "\t" . '<priority>' . $this->priority . '</priority>' . PHP_EOL;
			}
			$output .= '</url>' . PHP_EOL;

			$this->output .= $output;
		}
		closedir( $handle );
	}

	/**
	 * Set response headers.
	 *
	 * @return void
	 */
	private function set_headers(): void {
		// Sent the correct header so browsers display properly, with or without XSL.
		header( 'Content-Type: application/xml' );

		$cache_control = str_replace(['$expire_time'], [$this->expire_time], $this->cache_control);
		header('Cache-Control: ' . $cache_control);
		if ( $this->expire_time > 0 ) {
			header("Expires: " . gmdate("D, d M Y H:i:s", time() + $this->expire_time) . " GMT");
		}
	}

	/**
	 * Use cache if possible.
	 *
	 * @return bool
	 */
	private function use_cache(): bool {
		if ( ! $this->localcache ) {
			return false;
		} else {
			$lastModifiedTime = filemtime( $this->localcache_file );
			if ( $lastModifiedTime + $this->localcache_expire < time() ) {
				return false;
			} else {
				$cache = file_get_contents( $this->localcache_file );
				header( 'X-Cache: HIT from localhost' );
				echo $cache;
				return true;
			}
		}
	}

	/**
	 * Output our XML sitemap.
	 *
	 * @return void
	 */
	private function output(): void {
		$head = '<?xml version="1.0" encoding="utf-8"?>' . PHP_EOL;
		if ( ! empty( $this->xsl ) ) {
			$head .= '<?xml-stylesheet type="text/xsl" href="' . $this->url . $this->xsl . '"?>' . PHP_EOL;
		}
		$head .= '<urlset xmlns="https://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL;

		$last_mod = date( 'c', $this->last_mod_date );
		$body = str_replace('${last_mod_date}', $last_mod, $this->output);

		$foot = '</urlset>' . PHP_EOL;

		$output = $head . $body . $foot;
		echo $output;

		if ( $this->localcache ) {
			header( 'X-Cache: MISS from localhost' );
			file_put_contents( $this->localcache_file, $output );
		}
	}
}

$joost_xml = new Joost_XML_Sitemap_PHP();
$joost_xml->generate();
