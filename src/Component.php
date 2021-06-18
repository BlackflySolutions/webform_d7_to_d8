<?php

namespace Drupal\webform_d7_to_d8;

use Drupal\webform_d7_to_d8\traits\Utilities;

/**
 * Represents a webform component.
 */
class Component {

  use Utilities;

  /**
   * Constructor.
   *
   * @param Webform $webform
   *   A webform to which this component belongs
   *   (is a \Drupal\webform_d7_to_d8\Webform).
   * @param int $cid
   *   A component ID on the legacy database.
   * @param array $info
   *   Extra info about the component, corresponds to an associative array
   *   of legacy column names.
   * @param array $options
   *   Options originally passed to the migrator (for example ['nid' => 123])
   *   and documented in ./README.md.
   */
  public function __construct(Webform $webform, int $cid, array $info, array $options) {
    $this->webform = $webform;
    $this->cid = $cid;
    $this->info = $info;
    $this->options = $options;
  }

  /**
   * Based on legacy data, create a Drupal 8 form element.
   *
   * @return array
   *   An associative array with keys '#title', '#type'...
   *
   * @throws Exception
   */
  public function createFormElement() : array {
    $info = $this->info;
    $return = [
      '#title' => $info['name'],
      '#type' => $info['type'],
      '#required' => $info['required'],
      '#default_value' => '',
      '#title_display' => 'invisible',
    ];

    switch ($info['form_key']) {
      case 'utm_campaign':
        $return['#default_value'] = '[current-page:query:utm_campaign:clear]';
        break;
      case 'utm_content':
        $return['#default_value'] = '[current-page:query:utm_content:clear]';
        break;
      case 'utm_medium':
        $return['#default_value'] = '[current-page:query:utm_medium:clear]';
        break;
      case 'utm_source':
        $return['#default_value'] = '[current-page:query:utm_source:clear]';
        break;
      case 'utm_term':
        $return['#default_value'] = '7010B000000sC3p';
        break;
      case 'privacy_policy':
        $return['#required_error'] = 'Privacy policy field is required.';
        break;
    }
    $extra_info = unserialize($info['extra']);
    if (isset($extra_info['items']) && !empty($extra_info['items'])) {
      $options = explode(PHP_EOL, $extra_info['items']);
      $arrLength = count($options);
      $option_array = array();
      foreach ($options as $key => $option) {
        $key_value = explode('|', $option);
        $option_array[$key_value[0]] = $key_value[1];
        if ($arrLength == 1) {
          $checkbox_label = $key_value[1];
        }
      }
    }

    switch ($info['type']) {
      case 'email':
        $return['#default_value'] = '[current-user:mail]';
        break;
      case 'select':
        $return['#empty_option'] = $info['name'];
        break;
      case 'hidden':
        if (!empty($info['value'])) {
          $return['#default_value'] = $info['value'];
        }
        break;
      case 'processed_text':
        $return['#format'] = 'full_html';
        $return['#text'] = $info['value'];
        break;
      case 'checkboxes':
        $return['description_display'] = $info['invisible'];
        if (!empty($checkbox_label)) {
          $info['description'] = $info['name'] = $checkbox_label;
        }
        break;
      case 'checkbox':
        $return['#description'] = $info['name'] = $checkbox_label;
        $return['#description_display'] = $info['invisible'];
        $return['#options'] = $option_array;
        break;
      case 'radios':
        $return['#description'] = $info['name'];
        $return['#description_display'] = $info['invisible'];
        $return['#options'] = $option_array;
        $return['#title_display'] = 'before';
        break;
    }


    $this->extraInfo($return);

    if ($info['form_key'] == 'gdpr_country') {
      $return['#options'] = 'country_codes';
    }

    return $return;
  }

  /**
   * Add extra information to a form element if necessary.
   *
   * @param array $array
   *   An associative array with keys '#title', '#type'... This can be
   *   modified by this function if necessary.
   *
   * @throws Exception
   */
  public function extraInfo(&$array) {

  }

  /**
   * Get the legacy component ID.
   *
   * @return int
   *   The cid.
   */
  public function getCid() : int {
    return $this->cid;
  }

  /**
   * Return a form array with only the current element, keyed by form_key.
   *
   * @return array
   *   The result of ::createFormElement(), keyed by the form_key.
   */
  public function toFormArray() : array {
    $info = $this->info;
    return [
      $info['form_key'] => $this->createFormElement(),
    ];
  }

}
