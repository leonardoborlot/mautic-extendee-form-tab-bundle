<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\MauticExtendeeFormTabBundle\EventListener;

use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use Mautic\CampaignBundle\Event\CampaignExecutionEvent;
use Mautic\CampaignBundle\Event\PendingEvent;
use Mautic\CampaignBundle\Model\EventModel;
use Mautic\ChannelBundle\Model\MessageQueueModel;
use Mautic\CoreBundle\Model\FormModel;
use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Event\EmailOpenEvent;
use Mautic\EmailBundle\Event\EmailReplyEvent;
use Mautic\EmailBundle\Exception\EmailCouldNotBeSentException;
use Mautic\EmailBundle\Helper\UrlMatcher;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\EmailBundle\Model\SendEmailToUser;
use Mautic\FormBundle\Model\FieldModel;
use Mautic\FormBundle\Model\SubmissionModel;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\PageBundle\Entity\Hit;
use MauticPlugin\MauticExtendeeFormTabBundle\Helper\FormTabHelper;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Translation\TranslatorInterface;


/**
 * Class CampaginFormResultsSubscriber.
 */
class CampaginFormResultsSubscriber implements EventSubscriberInterface
{
    /**
     * @var LeadModel
     */
    protected $leadModel;

    /**
     * @var EmailModel
     */
    protected $emailModel;

    /**
     * @var EmailModel
     */
    protected $messageQueueModel;

    /**
     * @var EventModel
     */
    protected $campaignEventModel;

    /**
     * @var SendEmailToUser
     */
    private $sendEmailToUser;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    /**
     * @var FormModel
     */
    private $formModel;

    /**
     * @var FieldModel
     */
    private $fieldModel;

    /**
     * @var SubmissionModel
     */
    private $submissionModel;

    /**
     * @var FormTabHelper
     */
    private $formTabHelper;

    /**
     * @param LeadModel           $leadModel
     * @param EmailModel          $emailModel
     * @param EventModel          $eventModel
     * @param MessageQueueModel   $messageQueueModel
     * @param SendEmailToUser     $sendEmailToUser
     * @param TranslatorInterface $translator
     * @param FormModel           $formModel
     * @param FieldModel          $fieldModel
     * @param SubmissionModel     $submissionModel
     * @param FormTabHelper       $formTabHelper
     */
    public function __construct(
        LeadModel $leadModel,
        EmailModel $emailModel,
        EventModel $eventModel,
        MessageQueueModel $messageQueueModel,
        SendEmailToUser $sendEmailToUser,
        TranslatorInterface $translator,
        FormModel $formModel,
        FieldModel $fieldModel,
        SubmissionModel $submissionModel,
        FormTabHelper $formTabHelper
    ) {
        $this->leadModel          = $leadModel;
        $this->emailModel         = $emailModel;
        $this->campaignEventModel = $eventModel;
        $this->messageQueueModel  = $messageQueueModel;
        $this->sendEmailToUser    = $sendEmailToUser;
        $this->translator         = $translator;
        $this->formModel          = $formModel;
        $this->fieldModel         = $fieldModel;
        $this->submissionModel    = $submissionModel;
        $this->formTabHelper      = $formTabHelper;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            CampaignEvents::CAMPAIGN_ON_BUILD     => ['onCampaignBuild', 0],
            EmailEvents::ON_CAMPAIGN_BATCH_ACTION => [
                ['onCampaignTriggerActionSendEmailToContact', 0],
            ],
        ];
    }

    /**
     * @param CampaignBuilderEvent $event
     */
    public function onCampaignBuild(CampaignBuilderEvent $event)
    {

        $event->addAction(
            'email.send.form.results',
            [
                'label'                  => 'mautic.extendee.form.tab.campaign.event.send',
                'description'            => 'mautic.extendee.form.tab.campaign.event.send.desc',
                'batchEventName'         => EmailEvents::ON_CAMPAIGN_BATCH_ACTION,
                'formType'               => 'emailsend_list',
                'formTypeOptions'        => ['update_select' => 'campaignevent_properties_email'],
                'formTheme'              => 'MauticEmailBundle:FormTheme\EmailSendList',
                'channel'                => 'email',
                'channelIdField'         => 'email',
                'connectionRestrictions' => [
                    'anchor' => [
                        'condition.inaction',
                    ],
                    'source' => [
                        'condition' => [
                            'form.field_value',
                        ],
                    ],
                ],
            ]
        );
    }


    /**
     * Triggers the action which sends email to contacts.
     *
     * @param PendingEvent $event
     *
     * @throws \Doctrine\ORM\ORMException
     */
    public function onCampaignTriggerActionSendEmailToContact(PendingEvent $event)
    {
        if (!$event->checkContext('email.send.form.results')) {
            return;
        }

        $config = $event->getEvent()->getProperties();

        $emailId = (int) $config['email'];
        $email   = $this->emailModel->getEntity($emailId);

        if (!$email || !$email->isPublished()) {
            $event->failAll('Email not found or published');

            return;
        }


        $eventParent = $event->getEvent()->getParent();
        if (empty($eventParent) || $eventParent->getType() !== 'form.field_value') {
            $event->failAll('Parent condition not found.');

            return;
        }

        $form = $this->formModel->getRepository()->findOneById($eventParent->getProperties()['form']);

        if (!$form || !$form->getId()) {
            $event->failAll('Parent form not found.');

            return;
        }

        $event->setChannel('email', $emailId);

        $options = [
            'source'        => ['campaign.event', $event->getEvent()->getId()],
            'return_errors' => true,
            'dnc_as_error'  => true,
        ];

        // Determine if this email is transactional/marketing
        $pending         = $event->getPending();
        $contacts        = $event->getContacts();
        $contactIds      = $event->getContactIds();
        $credentialArray = [];
        $resultsArray    = [];

        /**
         * @var int
         * @var Lead $contact
         */
        $emailContent = $email->getCustomHtml();
        foreach ($contacts as $logId => $contact) {
            $leadCredentials = $contact->getProfileFields();

            // Set owner_id to support the "Owner is mailer" feature
            if ($contact->getOwner()) {
                $leadCredentials['owner_id'] = $contact->getOwner()->getId();
            }

            if (empty($leadCredentials['email'])) {
                // Pass with a note to the UI because no use retrying
                $event->passWithError(
                    $pending->get($logId),
                    $this->translator->trans(
                        'mautic.email.contact_has_no_email',
                        ['%contact%' => $contact->getPrimaryIdentifier()]
                    )
                );
                unset($contactIds[$contact->getId()]);
                continue;
            }

            $formResults = $this->formTabHelper->getFormWithResult($form, $contact->getId(), true);
            if (empty($formResults['results']['count'])) {
                unset($contactIds[$contact->getId()]);;
                continue;
            }

            $reason = [];
            foreach ($formResults['results']['results'] as $results) {
                $tokens = $this->findTokens($emailContent, $results['results']);
                $newEmailContent = str_replace(array_keys($tokens), $tokens, $emailContent);
                // replace all form field tokens
                $email->setCustomHtml($newEmailContent);
                $result = $this->emailModel->sendEmail($email, $leadCredentials, $options);
                if (is_array($result)) {
                    $reason[] = implode('<br />', $result);
                } elseif (true !== $result) {
                    $reason[] = $result;
                }
            }

            if (!empty($reason)) {
                $event->fail($pending->get($logId), implode('<br />', $reason));
            } else {
                $event->pass($pending->get($logId));
            }
        }
    }

    public function findTokens($content, $results)
    {

        // Search for bracket or bracket encoded
        // @deprecated BC support for leadfield
        $tokenRegex = [
            '/({|%7B)formfield=(.*?)(}|%7D)/',
        ];
        $tokenList  = [];

        foreach ($tokenRegex as $regex) {
            $foundMatches = preg_match_all($regex, $content, $matches);
            if ($foundMatches) {
                foreach ($matches[2] as $key => $match) {
                    $token = $matches[0][$key];

                    if (isset($tokenList[$token])) {
                        continue;
                    }

                    if (!empty($results[$match])) {
                        $tokenList[$token] = $results[$match]['value'];
                    }else{
                        $tokenList[$token] = '';
                    }
                }
            }
        }

        return $tokenList;
    }
}
