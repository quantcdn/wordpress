<?php

use Quant\Client;

if (!function_exists('quant_redirect_should_process')) {

  /**
   * Check whether this is a redirect we can action.
   *
   * @param Red_Item $redirect
   * @return void
   */
  function quant_redirect_should_process($redirect)
  {
    if ($redirect->is_regex()) {
      return FALSE;
    }

    // We only support URL matches.
    if ($redirect->get_match_type() != 'url') {
      return FALSE;
    }

    // We only support URL redirects.
    if ($redirect->get_action_type() != 'url') {
      return FALSE;
    }

    // We only support enabled URL redirects.
    if (!$redirect->is_enabled()) {
      return FALSE;
    }

    // If the action is not a string we cannot process.
    if (!is_string($redirect->get_action_data())) {
      return FALSE;
    }

    return TRUE;
  }

}

if (!function_exists('quant_redirection_redirect_updated')) {
    /**
     * Post updated redirect when updated.
     *
     * @param Red_Item $redirect
     * @return void
     */
    function quant_redirection_redirect_updated($redirect)
    {

      // New redirects will be the inserted ID.
      $is_new = is_int($redirect);

      if (!$is_new) {
        // This is the previous redirect.
        // @todo: Delete/unpublish if relevant.
        $old_redirect = $redirect;

        // Once we flush we can load the updated values.
        Red_Module::flush( $redirect->get_group_id() );

        // Reload the redirect.
        $redirect = Red_Item::get_by_id( $redirect->get_id() );
        if ( !$redirect ) {
          return;
        }
      }
      else {
        $redirect = Red_Item::get_by_id( $redirect );
      }

      if (!quant_redirect_should_process($redirect)) {
        return;
      }

      $code = $redirect->get_action_code() == '301' ? 301 : 302;
      $source = $redirect->get_url();
      $dest = $redirect->get_action_data();

      $client = new Client();
      $client->redirect($source, $dest, $code);
    }

}

if (!function_exists('quant_redirection_redirect_deleted')) {
  /**
   * Remove redirect from Quant when redirect is deleted.
   *
   * @param Red_Item $redirect
   * @return void
   */
  function quant_redirection_redirect_deleted($redirect)
  {

    if (!quant_redirect_should_process($redirect)) {
      return;
    }

    $client = new Client();
    $client->unpublish($redirect->get_url());

  }
}