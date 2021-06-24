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

    $extra_info = unserialize($info['extra']);

    if (isset($info['#states']['visible'])) {
      $return['#states']['visible'] = $info['#states']['visible'];
    }

    if (isset($extra_info['webform_conditional_field_value']) && !empty($extra_info['webform_conditional_field_value'])) {
      $conditional_value = $extra_info['webform_conditional_field_value'];
    }
    if (isset($extra_info['webform_conditional_operator']) && !empty($extra_info['webform_conditional_operator'])) {
      $conditional_operator = $extra_info['webform_conditional_operator'];
    }
    if (isset($extra_info['webform_conditional_cid']) && !empty($extra_info['webform_conditional_cid'])) {
      $conditional_cid = $extra_info['webform_conditional_cid'];
    }
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

    if (!empty($info['value']) && $info['type'] != 'processed_text') {
      $info['value'] = str_replace("%first_name", "[current-user:field_first_name]", $info['value']);
      $info['value'] = str_replace("%last_name", "[current-user:field_last_name]", $info['value']);
      $info['value'] = str_replace("%phone", "[current-user:field_user_phone]", $info['value']);
      $info['value'] = str_replace("%country", "[current-user:field_user_country]", $info['value']);
      $info['value'] = str_replace("%organization", "[current-user:field_user_organization]", $info['value']);
      $info['value'] = str_replace("%designation", "[current-user:field_user_designation]", $info['value']);
      $return['#default_value'] = $info['value'];
    }
    switch ($info['type']) {
      case 'email':
        $return['#default_value'] = '[current-user:mail]';
        $return['#placeholder'] = $info['name'] . '*';
        break;
      case 'textfield':
        if ($info['required'] == '1') {
          $return['#placeholder'] = $info['name'] . '*';
        }
        else {
          $return['#placeholder'] = $info['name'];
        }
        break;
      case 'select':
        $return['#empty_option'] = $info['name'];
        break;
      case 'processed_text':
        $return['#format'] = 'full_html';
        $return['#text'] = $info['value'];
        break;
      case 'checkboxes':
        $return['#options'] = $option_array;
        $return['#description'] = $info['name'];
        $return['#description_display'] = 'invisible';
        unset($return['#title_display']);
        break;
      case 'checkbox':
        $return['#description'] = $info['name'] = $checkbox_label;
        $return['#description_display'] = 'invisible';
        $return['#title_display'] = 'after';
        break;
      case 'radios':
        $return['#description'] = $info['name'];
        $return['#description_display'] = 'invisible';
        $return['#options'] = $option_array;
        $return['#title_display'] = 'before';
        break;
    }

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
      case 'actions':
        if (!empty($info['value'])) {
          $return['#submit__label'] = $info['value'];
          break;
        }
      case 'add_to_schedule':
        $return['#title_display'] = 'none';
        $return['#trim'] = true;
        $return['#sanitize'] = true;
        $return['#download'] = true;
        $return['#url'] = '[webform_submission:add-to-schedule]';
        break;
    }


    $this->extraInfo($return);

    if ($info['form_key'] == 'gdpr_country') {
      $return['#options'] = 'country_codes';
    }
    if ($info['form_key'] == 'designation' || $info['form_key'] == 'job_title') {
      $return['#options'] = 'designation';
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
