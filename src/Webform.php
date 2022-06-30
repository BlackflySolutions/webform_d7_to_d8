<?php

namespace Drupal\webform_d7_to_d8;

use Drupal\webform_d7_to_d8\traits\Utilities;
use Drupal\webform_d7_to_d8\Collection\Components;
use Drupal\webform_d7_to_d8\Collection\Submissions;
use Drupal\webform\Entity\Webform as DrupalWebform;
use Drupal\webform\Entity\WebformSubmission;
use Drupal\node\Entity\Node;

/**
 * Represents a webform.
 */
class Webform {

  use Utilities;

  /**
   * Constructor.
   *
   * @param int $nid
   *   The legacy Drupal node ID.
   * @param string $title
   *   The title of the legacy node which will become the title of the new
   *   webform (which, in Drupal 8, is not a node).
   * @param array $options
   *   Options originally passed to the migrator (for example ['nid' => 123])
   *   and documented in ./README.md.
   */
  public function __construct(int $nid, string $title, array $options) {
    $this->nid = $nid;
    $this->title = $title;
    $this->options = $options;
  }

  /**
   * Delete all submissions for this webform on the Drupal 8 database.
   *
   * This is never called, but is available to external code.
   *
   * @throws Exception
   */
  public function deleteSubmissions() {
    if (isset($this->options['simulate']) && $this->options['simulate']) {
      $this->print('SIMULATE: Delete submissions for webform before reimporting them.');
      return;
    }
    $query = $this->getConnection('default')->select('webform_submission', 'ws');
    $query->condition('ws.webform_id', 'webform_' . $this->getNid());
    $query->addField('ws', 'sid');
    $result = array_keys($query->execute()->fetchAllAssoc('sid'));

    $max = \Drupal::state()->get('webform_d7_to_d8_max_delete_items', 500);
    $this->print('Will delete @n submissions in chunks of @c to avoid avoid
      out of memory errors.', ['@n' => count($result), '@c' => $max]);

    $arrays = array_chunk($result, $max);

    $this->print('@n chunks generated.', ['@n' => count($arrays)]);

    $storage = \Drupal::entityTypeManager()->getStorage('webform_submission');
    foreach ($arrays as $array) {
      $submissions = WebformSubmission::loadMultiple($array);
      $this->print('Deleting @n submissions for webform @f',
        ['@n' => count($submissions), '@f' => $this->getNid()]);
      $storage->delete($submissions);
    }
  }

  /**
   * Return the first sid (submission id) to import.
   */
  public function firstSid() {
    return \Drupal::state()->get('webform_d7_to_d8', 0);
  }

  /**
   * Get the Drupal 8 Webform object.
   *
   * @return Drupal\webform\Entity\Webform
   *   The Drupal webform ojbect as DrupalWebform.
   */
  public function getDrupalObject() : DrupalWebform {
    return $this->drupalObject;
  }

  /**
   * Getter for nid.
   *
   * @return int
   *   The webform nid.
   */
  public function getNid() {
    return $this->nid;
  }

  /**
   * Import this webform, all its components and all its submissions.
   *
   * @param array $options
   *   Options originally passed to the migrator (for example ['nid' => 123])
   *   and documented in ./README.md.
   *
   * @throws \Exception
   */
  public function process(array $options = []) {
    $new_only = empty($options['new-only']) ? FALSE : TRUE;
    $continue = TRUE;
    $this->drupalObject = $this->updateD8Webform([
      'id' => 'webform_' . $this->getNid(),
      'title' => $this->title,
    ], $options, $new_only, $continue);
    // Update confirmation URL.
    $this->setWebformConfirmation();
    if ($continue) {
      $components = $this->webformComponents();
      $this->print($this->t('Form @n: Processing components', ['@n' => $this->getNid()]));
      $this->updateD8Components($this->getDrupalObject(), $components->toFormArray(), $this->options);
    }
    else {
      $this->print($this->t('Form @n: NOT processing components', ['@n' => $this->getNid()]));
    }
    $submissions = $this->webformSubmissions()->toArray();
    foreach ($submissions as $submission) {
      $this->print($this->t('Form @n: Processing submission @s with user @u', ['@n' => $this->getNid(), '@s' => $submission->getSid(), '@u' => $submission->getUid()]));
      try {
        $submission->process();
      }
      catch (\Throwable $t) {
        $this->print('ERROR with submission (errors and possible fixes will be shown at the end of the process)');
        WebformMigrator::instance()->addError($t->getMessage());
      }
    }
    $node = Node::load($this->getNid());
    if (isset($this->options['simulate']) && $this->options['simulate']) {
      $this->print('SIMULATE: Linking node to the webform we just created.');
    }
    elseif ($node) {
      try {
        $this->print('Linking node @n to the webform we just created.', ['@n' => $this->getNid()]);
        $node->webform->target_id = 'webform_' . $this->getNid();
        $node->save();
      }
      catch (\Exception $e) {
        $this->print('Node @n exists on the target environment, but we could not set the webform field to the appropriate webform, moving on...', ['@n' => $this->getNid()]);
      }
    }
    else {
      $this->print('Node @n does not exist on the target environment, moving on...', ['@n' => $this->getNid()]);
    }
    // Attach email handler.
    $this->print('Adding Email Handlers associated with this webform....');
    $this->webformEmailHandlers();
  }

  /**
   * Set the Drupal 8 Webform object.
   *
   * @param Drupal\webform\Entity\Webform $webform
   *   The Drupal webform ojbect as DrupalWebform.
   */
  public function setDrupalObject(DrupalWebform $webform) {
    $this->drupalObject = $webform;
  }

  /**
   * Get all legacy submitted data for this webform.
   *
   * @return array
   *   Submissions keyed by legacy sid (submission ID).
   *
   * @throws Exception
   */
  public function submittedData() : array {
    $return = [];
    $query = $this->getConnection('upgrade')->select('webform_submitted_data', 'wd');
    $query->join('webform_component', 'c', 'c.cid = wd.cid AND c.nid = wd.nid');
    $query->addField('c', 'form_key');
    $query->addField('wd', 'sid');
    $query->addField('wd', 'data');
    $query->condition('wd.nid', $this->getNid(), '=');
    $result = $query->execute()->fetchAll();
    $return = [];
    foreach ($result as $row) {
      $return[$row->sid][$row->form_key] = [
        'value' => $row->data,
      ];
    }
    return $return;
  }

  /**
   * Get all legacy components for a given webform.
   *
   * @return Components
   *   The components.
   *
   * @throws \Exception
   */
   public function webformComponents() : Components {
     $query = $this->getConnection('upgrade')->select('webform_component', 'wc');
     $query->addField('wc', 'cid');
     $query->addField('wc', 'form_key');
     $query->addField('wc', 'name');
     $query->addField('wc', 'mandatory');
     $query->addField('wc', 'type');
     $query->addField('wc', 'extra');
     $query->addField('wc', 'value');
     $query->condition('nid', $this->getNid(), '=');
     $query->orderBy('weight');

     $result = $query->execute()->fetchAllAssoc('cid');

     $submit_query = $this->getConnection('upgrade')->select('webform', 'w');
     $submit_query->addField('w', 'submit_text');
     $submit_query->condition('nid', $this->getNid(), '=');
     $submit_text = $submit_query->execute()->fetchField();

     $disclaimer_query = $this->getConnection('upgrade')->select('webform_component', 'wc');
     $disclaimer_query->addField('wc', 'cid');
     $disclaimer_query->condition('nid', $this->getNid(), '=');
     $disclaimer_query->condition('form_key', 'disclaimer_markup', '=');
     $disclaimer_cid = $disclaimer_query->execute()->fetchField();

     $array = [];
     $submit_button = [];
     $submit_button['cid'] = '1000';
     $submit_button['type'] = 'webform_actions';
     $submit_button['name'] = 'Submit button(s)';
     $submit_button['form_key'] = 'actions';
     $submit_button['value'] = '';
     if (!empty($submit_text)) {
       $submit_button['value'] = $submit_text;
     }
     $submit_button_obj = (object) $submit_button;
     $result['1000'] = $submit_button_obj;

     if (!empty($disclaimer_cid)) {
       $disclaimer_new = $result[$disclaimer_cid];
       unset($result[$disclaimer_cid]);
       $result[$disclaimer_cid] = $disclaimer_new;
     }
     $file_obj = (object) array('cid' => '1001', 'type' => 'attachment_url', 'name' => 'Add to schedule', 'form_key' => 'add_to_schedule');
     $result['1001'] = $file_obj;

     foreach ($result as $cid => $info) {
       $info = (array) $info;
       $info['required'] = $info['mandatory'];
       unset($info['mandatory']);

       $extra_info = unserialize($info['extra']);

       if (isset($extra_info['webform_conditional_cid']) && !empty($extra_info['webform_conditional_cid'])) {
         $conditional_value = $extra_info['webform_conditional_field_value'];
         $conditional_operator = $extra_info['webform_conditional_operator'];
         $conditional_cid = $extra_info['webform_conditional_cid'];
         $conditional_arr = explode(PHP_EOL, $conditional_value);

         $conditional_query = $this->getConnection('upgrade')->select('webform_component', 'wc');
         $conditional_query->addField('wc', 'form_key');
         $conditional_query->addField('wc', 'extra');
         $conditional_query->condition('nid', $this->getNid(), '=');
         $conditional_query->condition('cid', $conditional_cid, '=');
         $conditional_info = $conditional_query->execute()->fetchAssoc();

         $conditional_items = [];

         if (!empty($conditional_info)) {
           $conditional_extra_info = unserialize($conditional_info['extra']);
           $conditional_options = explode(PHP_EOL, $conditional_extra_info['items']);
           foreach ($conditional_options as $conditional_option) {
             $element_arr = explode('|', $conditional_option);
             if (in_array($element_arr[0], $conditional_arr)) {
               $conditional_items[] = array(':input[name="' . $conditional_info['form_key'] . '"]' => ['value' => $element_arr[1]]);
               // $conditional_items[$element_arr[0]] = $element_arr[1];
               $conditional_items[] = 'xor';
             }
           }
         }
         if (!empty($conditional_items)) {
           array_pop($conditional_items);
           $info['#states']['visible'] = $conditional_items;
         }
       }

       if ($info['form_key'] == 'organization') {
         $info['required'] = '0';
       }
       if ($info['type'] == 'markup') {
         $info['type'] = 'processed_text';
       }
       if ($info['type'] == 'select') {
         // If aslist != 0, change field type to checkbox/radio.
         if ($extra_info['aslist'] == 0) {
           // Create options for checkboxes/radios.
           $options = explode(PHP_EOL, $extra_info['items']);
           $arrLength = count($options);

           if ($extra_info['multiple'] == 1) {
             if ($arrLength == 1) {
               $info['type'] = 'checkbox';
             }
             else {
               $info['type'] = 'checkboxes';
             }
           }
           else {
             $info['type'] = 'radios';
           }
         }
       }

       if ($info['form_key'] == 'designation' || $info['form_key'] == 'job_title') {
         $info['type'] = 'select';
       }

       $array[] = ComponentFactory::instance()->create($this, $cid, $info, $this->options);
     }

     return new Components($array);
   }

  /**
   * Set confirmation URL in the webform.
   */
  protected function setWebformConfirmation() {
    $query = $this->getConnection('upgrade')->select('webform', 'w')
      ->fields('w', ['confirmation', 'redirect_url'])
      ->condition('nid', $this->getNid());
    $result = $query->execute()->fetchAssoc();
    // Define standard confirmation type.
    $confirmation_settings = [];
    foreach ($result as $key => $value) {
      switch ($key) {
        case 'confirmation':
          if ($value == '<confirmation>') {
            // @TODO Update the confirmation URL here.
            $value = '';
          }
          $confirmation_settings['confirmation_message'] = $value;
          break;
        case 'redirect_url':
          $confirmation_settings['confirmation_url'] = $value;
          break;
      }
    }
    if ($confirmation_settings['confirmation_message'] && $confirmation_settings['confirmation_url'] == '<none>') {
      $confirmation_settings['confirmation_type'] = 'message';
    }
    elseif ($confirmation_settings['confirmation_message'] && $confirmation_settings['confirmation_url']) {
      $confirmation_settings['confirmation_type'] = 'url_message';
    }
    elseif (empty($confirmation_settings['confirmation_message']) && $confirmation_settings['confirmation_url']) {
      $confirmation_settings['confirmation_type'] = 'url';
    }
    elseif ($confirmation_settings['confirmation_message'] && empty($confirmation_settings['confirmation_url'])) {
      $confirmation_settings['confirmation_type'] = 'message';
    }
    else {
      $confirmation_settings['confirmation_type'] = 'none';
    }
    // Updating form with confirmation properties.
    $this->drupalObject = $this->updateD8Webform([
      'id' => 'webform_' . $this->getNid(),
      'title' => $this->title,
      'settings' => $confirmation_settings,
    ], [], FALSE);
  }

  /**
   * Get all the email handlers.
   */
  public function webformEmailHandlers() {
    $query = $this->getConnection('upgrade')->select('webform_emails', 'we');
    $query->fields('we');
    $query->condition('nid', $this->getNid());
    $result = $query->execute()->fetchAll();

    $webform = $this->getDrupalObject();
    // Create instance of webform handler plugin.
    foreach ($result as $key => $value) {
      $value = (array) $value;
      $array = [];
      $webform_handler_manager = \Drupal::service('plugin.manager.webform.handler');
      $webform_handler = $webform_handler_manager->createInstance('email');
      $webform_handler->setConfiguration([
        'id' => 'email',
        'label' => 'Email',
        'handler_id' => $webform->id() . '_email_' . $key,
        'status' => TRUE,
        'weight' => 0,
        'settings' => [
          'states' => ['completed'],
          'to_mail' => $this->getEmailFormKey($value['email']),
          'to_options' => [],
          'cc_mail' => '',
          'cc_options' => [],
          'bcc_mail' => '',
          'bcc_options' => [],
          'from_mail' => $value['from_address'] == 'default' ? '_default' : $value['from_address'],
          'from_options' => [],
          'from_name' => $value['from_name'] ?? 'default',
          'subject' => $value['subject'] == 'default' ? '_default' : $this->replaceToken($value['subject'], 'subject'),
          'body' => $value['template'] == 'default' ? '_default' : $this->replaceToken($value['template']),
          'excluded_elements' => [],
          'html' => TRUE,
          'twig' => TRUE,
          'attachments' => TRUE,
          'debug' => 0,
          'reply_to' => '',
          'return_path' => '',
        ],
      ]);

      $webform->setOriginalId($webform->id());
      // Add handle to the webform, which triggers another save().
      $webform->addWebformHandler($webform_handler);

    }
  }

  /**
   * Get email id form key if present.
   *
   * @param string $cid
   *   The cid value.
   *
   * @return string
   *   Proper form key for email.
   */
  public function getEmailFormKey(string $cid) {
    $email = $this->getConnection('upgrade')->select('webform_component', 'wc');
    $email->addField('wc', 'form_key');
    $email->condition('nid', $this->getNid());
    $email->condition('cid', $cid);
    $email_key = $email->execute()->fetchField();

    return $email_key ? '[webform_submission:values:' . $email_key . ':raw]' : $cid;
  }

  /**
   * Replace D7 token with existing D8 token.
   *
   * @param string $template
   *   The string containing token.
   * @param string $type
   *   The type of attribute of webform.
   *
   * @return string
   *   Template body with updated tokens.
   */
  public function replaceToken(string $template, string $type = 'template') {
    $token_mapping = [
      'template' => [
        '%title' => "{{ webform_token('[webform_submission:source-title]', webform_submission) }}",
      ],
      'subject' => [
        '%title' => '[webform_submission:source-title]',
      ],
    ];
    $template = str_replace('%title', $token_mapping[$type]['%title'], $template);
    // Replace the value token with twig data token.
    preg_match_all('/%value\[(.+)\]/', $template, $matches);
    if (array_key_exists(1, $matches)) {
      foreach ($matches[1] as $key => $value) {
        $replacement = '{{ data.' . $value . ' }}';
        $template = str_replace($matches[0][$key], $replacement, $template);
      }
    }
    return $template;
  }

  /**
   * Get all legacy submissions for a given webform.
   *
   * @return Submissions
   *   The submissions.
   *
   * @throws \Exception
   */
  public function webformSubmissions() : Submissions {
    if (isset($this->options['max_submissions']) && $this->options['max_submissions'] !== NULL) {
      $max = $this->options['max_submissions'];
      if ($max === 0) {
        $this->print('You specified max_submissions to 0, so no submissions will be loaded.');
        return new Submissions([]);
      }
    }

    $this->print('Only getting submission ids > @s because we have already imported the others.', ['@s' => $this->firstSid()]);

    $query = $this->getConnection('upgrade')->select('webform_submissions', 'ws');
    $query->addField('ws', 'sid');
    $query->addField('ws', 'uid');
    $query->addField('ws', 'remote_addr');
    $query->condition('nid', $this->getNid(), '=');
    $query->condition('sid', $this->firstSid(), '>');

    if (isset($max)) {
      $this->print('You speicifc max_submissions to @n, so only some submissions will be processed.', ['@n' => $max]);
      $query->range(0, $max);
    }
    $submitted_data = $this->submittedData();

    $result = $query->execute()->fetchAllAssoc('sid');
    $array = [];
    foreach ($result as $sid => $info) {
      if (empty($submitted_data[$sid])) {
        $this->print('In the legacy system, there is a submission with');
        $this->print('id @id, but it does not have any associated data.', ['@id' => $sid]);
        $this->print('Ignoring it and moving on...');
        continue;
      }
      $this->print('Importing submission @s', ['@s' => $sid]);
      $array[] = new Submission($this, $sid, (array) $info, $submitted_data[$sid], $this->options);
    }

    return new Submissions($array);
  }

}
