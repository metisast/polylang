<?php

/*
 * a class to import languages and translations information form a WXR file
 *
 * @since 1.2
 */
class PLL_WP_Import extends WP_Import {

	/*
	 * overrides WP_Import::process_terms to remap terms translations
	 *
	 * @since 1.2
	 */
	function process_terms() {
		// store this for future usage as parent function unsets $this->terms
		foreach ($this->terms as $term) {
			if ('post_translations' == $term['term_taxonomy'])
				$this->post_translations[] = $term;
			if ('term_translations' == $term['term_taxonomy'])
				$term_translations[] = $term;
		}

		parent::process_terms();

		global $polylang;
		$polylang->model->clean_languages_cache(); // to update the languages list if needed

		if (($options = get_option('polylang')) && empty($options['default_lang']) && ($languages = $polylang->model->get_languages_list())) {
			// assign the default language if importer created the first language
			$default_lang = reset($languages);
			$options['default_lang'] = $default_lang->slug;
			update_option('polylang', $options);

			// and assign default language to default category
			$poylang->model->set_term_language((int) get_option('default_category'), $default_lang);
		}

		$this->remap_terms_relations($term_translations);
		$this->remap_translations($term_translations, $this->processed_terms);
	}

	/*
	 * overrides WP_Import::process_post to remap posts translations
	 * also merges strings translations from the WXR file to the existing ones
	 *
	 * @since 1.2
	 */
	function process_posts() {
		// store this for future usage as parent function unset $this->posts
		foreach ($this->posts as $post) {
			if ('nav_menu_item' == $post['post_type'])
				$menu_items[] = $post;

			if (0 === strpos($post['post_title'], 'polylang_mo_'))
				$mo_posts[] = $post;
		}

		if (!empty($mo_posts))
			new PLL_MO(); // just to register the polylang_mo post type before processing posts

		parent::process_posts();

		$this->remap_translations($this->post_translations, $this->processed_posts);
		unset($this->post_translations);

		// language switcher menu items
		foreach ($menu_items as $item) {
			foreach ($item['postmeta'] as $meta) {
				if ('_pll_menu_item' == $meta['key'])
					update_post_meta($this->processed_menu_items[$item['post_id']], '_pll_menu_item', maybe_unserialize($meta['value']));
			}
		}

		// merge strings translations
		foreach ($mo_posts as $post) {
			$lang_id = $this->processed_terms[(int) substr($post['post_title'], 12)];

			if ($strings = unserialize($post['post_content'])) {
				$mo = new PLL_MO();
				$mo->import_from_db($lang_id);
				foreach ($strings as $msg)
					$mo->add_entry_or_merge($mo->make_entry($msg[0], $msg[1]));
				$mo->export_to_db($lang_id);
			}

			// delete the now useless imported post
			wp_delete_post($this->processed_posts[$post['post_id']], true);
		}
	}

	/*
	 * remaps terms languages
	 *
	 * @since 1.2
	 *
	 * @param array $terms array of terms in 'term_translations' taxonomy
	 */
	function remap_terms_relations(&$terms){
		global $polylang, $wpdb;

		foreach ($terms as $term) {
			$translations = unserialize($term['term_description']);
			foreach($translations as $slug => $old_id)
				if ($old_id) {
					// language relationship
					$trs[] = $wpdb->prepare('(%d, %d)', $this->processed_terms[$old_id], $polylang->model->get_language($slug)->tl_term_taxonomy_id);

					// translation relationship
					$trs[] = $wpdb->prepare('(%d, %d)', $this->processed_terms[$old_id], get_term($this->processed_terms[$term['term_id']], 'term_translations')->term_taxonomy_id );
				}
		}

		// insert term_relationships
		if (!empty($trs))
			$wpdb->query("INSERT INTO $wpdb->term_relationships (object_id, term_taxonomy_id) VALUES " . implode(',', $trs));
	}

	/*
	 * remaps translations for both posts and terms
	 *
	 * @since 1.2
	 *
	 * @param array $terms array of terms in 'post_translations' or 'term_translations' taxonomies
	 * @param array $processed_objects array of posts or terms processed by WordPress Importer
	 */
	function remap_translations(&$terms, &$processed_objects) {
		global $wpdb;

		foreach ($terms as $term) {
			$translations = unserialize($term['term_description']);
			foreach($translations as $slug => $old_id)
				if ($old_id)
					$translations[$slug] = $processed_objects[$old_id];

			$u['case'][] = $wpdb->prepare('WHEN %d THEN %s', $this->processed_terms[$term['term_id']], serialize($translations));
			$u['in'][] = (int) $this->processed_terms[$term['term_id']];
		}

		if (!empty($u))
			$wpdb->query("UPDATE $wpdb->term_taxonomy
				SET description = ( CASE term_id " . implode(' ', $u['case']) . " END )
				WHERE term_id IN ( " . implode(',', $u['in']) . " )");
	}
}
