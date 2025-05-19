<?php
declare(strict_types=1);

namespace In2code\Femanager\Controller;

use In2code\Femanager\Domain\Model\Log;
use In2code\Femanager\Domain\Model\User;
use In2code\Femanager\Event\InviteUserConfirmedEvent;
use In2code\Femanager\Event\InviteUserCreateEvent;
use In2code\Femanager\Event\InviteUserEditEvent;
use In2code\Femanager\Event\InviteUserUpdateEvent;
use In2code\Femanager\Utility\FrontendUtility;
use In2code\Femanager\Utility\HashUtility;
use In2code\Femanager\Utility\LocalizationUtility;
use In2code\Femanager\Utility\StringUtility;
use In2code\Femanager\Utility\UserUtility;
use TYPO3\CMS\Core\Messaging\AbstractMessage;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Property\TypeConverter\DateTimeConverter;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Vision05\EbgPortego\Domain\Model\SalesProspectCreate;

/**
 * Class InvitationController
 */
class InvitationController extends AbstractController implements LoggerAwareInterface
{

    use LoggerAwareTrait;

    /**
     * @var \Vision05\EbgPortego\Domain\Repository\SalesProspectRepository
     */
    protected $salesProspectRepository;

    /**
     * @param \Vision05\EbgPortego\Domain\Repository\SalesProspectRepository $salesProspectRepository
     */
    public function __construct(
        \Vision05\EbgPortego\Domain\Repository\SalesProspectRepository $salesProspectRepository
    )
    {
        $this->salesProspectRepository = $salesProspectRepository;
    }

    /**
     * action new
     *
     * @return void
     */
    public function newAction()
    {
        $this->allowedUserForInvitationNewAndCreate();
        $this->view->assign('allUserGroups', $this->allUserGroups);
        $this->assignForAll();
    }

    public function initializeCreateAction()
    {
        $this->logger->warning("Initializing create action. Parameters are: " . print_r($this->request->getArguments(), true));
    }

    /**
     * action create
     *
     * @param User $user
     * @TYPO3\CMS\Extbase\Annotation\Validate("In2code\Femanager\Domain\Validator\ServersideValidator", param="user")
     * @TYPO3\CMS\Extbase\Annotation\Validate("In2code\Femanager\Domain\Validator\PasswordValidator", param="user")
     * @TYPO3\CMS\Extbase\Annotation\Validate("In2code\Femanager\Domain\Validator\CaptchaValidator", param="user")
     * @return void
     */
    public function createAction(User $user)
    {
        $this->allowedUserForInvitationNewAndCreate();
        $user->setDisable(true);
        $user = FrontendUtility::forceValues(
            $user,
            $this->config['invitation.']['forceValues.']['beforeAnyConfirmation.']
        );
        $user = UserUtility::fallbackUsernameAndPassword($user);
        if ($this->settings['invitation']['fillEmailWithUsername'] === '1') {
            $user->setEmail($user->getUsername());
        }
        UserUtility::hashPassword($user, $this->settings['invitation']['misc']['passwordSave']);
        $this->eventDispatcher->dispatch(new InviteUserCreateEvent($user));
        $this->createAllConfirmed($user);
    }

    /**
     * Prefix method to createAction()
     *        Create Confirmation from Admin is not necessary
     *
     * @param User $user
     * @return void
     */
    public function createAllConfirmed(User $user)
    {
        $this->userRepository->add($user);
        $this->persistenceManager->persistAll();
        $this->addFlashMessage(LocalizationUtility::translate('createAndInvited'));
        $this->logUtility->log(Log::STATUS_INVITATIONPROFILECREATED, $user);

        // send confirmation mail to user
        $this->sendMailService->send(
            'invitation',
            StringUtility::makeEmailArray($user->getEmail(), $user->getUsername()),
            StringUtility::makeEmailArray($user->getEmail(), $user->getUsername()),
            'Profile creation with invitation',
            [
                'user' => $user,
                'settings' => $this->settings,
                'hash' => HashUtility::createHashForUser($user)
            ],
            $this->config['invitation.']['email.']['invitation.']
        );

        // send notify email to admin
        if ($this->settings['invitation']['notifyAdminStep1']) {
            $this->sendMailService->send(
                'invitationNotifyStep1',
                StringUtility::makeEmailArray(
                    $this->settings['invitation']['notifyAdminStep1'],
                    $this->settings['invitation']['email']['invitationAdminNotifyStep1']['receiver']['name']['value']
                ),
                StringUtility::makeEmailArray($user->getEmail(), $user->getUsername()),
                'Profile creation with invitation - Step 1',
                [
                    'user' => $user,
                    'settings' => $this->settings
                ],
                $this->config['invitation.']['email.']['invitationAdminNotifyStep1.']
            );
        }

        $this->eventDispatcher->dispatch(new InviteUserConfirmedEvent($user));

        $this->redirectByAction('invitation', 'redirectStep1');
        $uriBuilder = $this->getControllerContext()->getUriBuilder();
        $uri = $uriBuilder->reset()
            ->setAddQueryString(true)
            ->setAddQueryStringMethod('GET')
            ->uriFor('status');
        $this->redirectToUri($uri);
    }

    /**
     * action edit
     *
     * @param int $user User UID
     * @param string $hash
     * @return void
     */
    public function editAction($user, $hash = null)
    {
        $user = $this->userRepository->findByUid($user);

        /* Does not make sense, unless new registration would lead to new hash.
        if ($user !== null && $user->getCrdate() < (new \DateTime())->modify('-3 days') && !$user->getTxFemanagerInvitationcompleted()) {
            $this->userRepository->remove($user);
            $this->addFlashMessage(LocalizationUtility::translate('deletedOldInvitation'), '', AbstractMessage::ERROR);
            $uriBuilder = $this->getControllerContext()->getUriBuilder();
            $uri = $uriBuilder->reset()
                ->setAddQueryString(true)
                ->setAddQueryStringMethod('GET')
                ->uriFor('status');
            $this->redirectToUri($uri);
        }
        */


        // User must exist and hash must be valid
        if ($user === null || !HashUtility::validHash($hash, $user)) {

            if ($user === null) {
                $this->logger->warning("Confirming email failed: user does not exist");
            } else {
                $this->logger->warning("Confirming email failed with hash mismatch for user ."  . $user->getEmail());
            }

            $this->addFlashMessage(LocalizationUtility::translate('createFailedProfile'), '', AbstractMessage::ERROR);
            $uriBuilder = $this->getControllerContext()->getUriBuilder();
            $uri = $uriBuilder->reset()
                ->setAddQueryString(true)
                ->setAddQueryStringMethod('GET')
                ->uriFor('status');
            $this->redirectToUri($uri);
        }

        // User must not be deleted (deleted = 0) and not be activated (disable = 1)
        if ($user->getTxFemanagerInvitationcompleted()) {
            $this->addFlashMessage(LocalizationUtility::translate('userAlreadyConfirmed'), '', AbstractMessage::ERROR);
            $uriBuilder = $this->getControllerContext()->getUriBuilder();
            $uri = $uriBuilder->reset()
                ->setAddQueryString(true)
                ->setAddQueryStringMethod('GET')
                ->uriFor('status');
            $this->redirectToUri($uri);
        }

        $this->logger->warning("Confirming email for user ."  . $user->getEmail());

        $user->setDisable(false);
        $this->userRepository->update($user);
        $this->persistenceManager->persistAll();

        $this->eventDispatcher->dispatch(new InviteUserEditEvent($user, $hash));

        $this->logger->warning("Trying to call getProspectByEmail."  . $user->getEmail());

        $salesProspect = $this->salesProspectRepository->getProspectByEmail($user->getEmail());

        $this->logger->warning("Successfully called getProspectByEmail." . $user->getEmail());


        if (isset($salesProspect) && empty($user->getPortegoId())) {
            $user->setPortegoId($salesProspect->getId());
            $user->setCountry($salesProspect->getCountry());
            $user->setCity($salesProspect->getCity());
            $user->setZip($salesProspect->getPostCode());
            $user->setAddress($salesProspect->getStreet());
            $user->setTitle($salesProspect->getPreTitle());
            $user->setTitleSuffix($salesProspect->getPostTitle());
            $user->setTelephone($salesProspect->getPhone());
            $user->setFirstName($salesProspect->getFirstName());
            $user->setLastName($salesProspect->getLastName());
            $user->setNationality($salesProspect->getCitizenship());
            $user->setGender($this->genderStringToInt($salesProspect->getGender()));
            $user->setFamilyCount($salesProspect->getFamilySize());
            $user->setDateOfBirth($salesProspect->getBirthdate());
        }

        $this->view->assignMultiple(
            [
                'user' => $user,
                'hash' => $hash
            ]
        );

        $this->assignForAll();
    }

    public function initializeUpdateAction()
    {
        if ($this->arguments->hasArgument('user')) {
            $this->arguments['user']
                ->getPropertyMappingConfiguration()
                ->forProperty('dateOfBirth')
                ->setTypeConverterOption(
                    DateTimeConverter::class,
                    DateTimeConverter::CONFIGURATION_DATE_FORMAT,
                    LocalizationUtility::translate('tx_femanager_domain_model_user.dateFormat')
                );
        } else {

        }
    }

    /**
     * action update
     *
     * @param \In2code\Femanager\Domain\Model\User $user
     * @param string $hash
     * @TYPO3\CMS\Extbase\Annotation\Validate("In2code\Femanager\Domain\Validator\ServersideValidator", param="user")
     * @TYPO3\CMS\Extbase\Annotation\Validate("In2code\Femanager\Domain\Validator\PasswordValidator", param="user")
     * @return void
     */
    public function updateAction($user, $hash = null)
    {
        $this->logger->warning("Trying to update user  "  . $user->getEmail());
        if (!HashUtility::validHash($hash, $user)) {
            $this->logger->warning("Hash mismatch for user  "  . $user->getEmail());
            $this->addFlashMessage(
                LocalizationUtility::translateByState(Log::STATUS_PROFILEUPDATEREFUSEDSECURITY),
                '',
                AbstractMessage::ERROR
            );
            $uriBuilder = $this->getControllerContext()->getUriBuilder();
            $uri = $uriBuilder->reset()
                ->setAddQueryString(true)
                ->setAddQueryStringMethod('GET')
                ->uriFor('status');
            $this->redirectToUri($uri);
        }
        $this->addFlashMessage(LocalizationUtility::translate('createAndInvitedFinished'));
        $this->logUtility->log(Log::STATUS_INVITATIONPROFILEENABLED, $user);
        if ($this->settings['invitation']['notifyAdmin']) {
            $this->sendMailService->send(
                'invitationNotify',
                StringUtility::makeEmailArray(
                    $this->settings['invitation']['notifyAdmin'],
                    $this->settings['invitation']['email']['invitationAdminNotify']['receiver']['name']['value']
                ),
                StringUtility::makeEmailArray($user->getEmail(), $user->getUsername()),
                'Profile creation with invitation - Final',
                [
                    'user' => $user,
                    'settings' => $this->settings
                ],
                $this->config['invitation.']['email.']['invitationAdminNotify.']
            );
        }
        $user = UserUtility::overrideUserGroup($user, $this->settings, 'invitation');
        UserUtility::hashPassword($user, $this->settings['invitation']['misc']['passwordSave']);

        if (!$this->ensureInPortego($user)) {
            $this->logger->warning("Problem while ensuring prospect for user  "  . $user->getEmail());
        }

        $user->setTxFemanagerInvitationcompleted(true);
        $this->userRepository->update($user);
        $this->persistenceManager->persistAll();

        $this->logger->warning("Updated and persisted user  "  . $user->getEmail());

        UserUtility::login($user, $this->allConfig['persistence']['storagePid']);
        $this->eventDispatcher->dispatch(new InviteUserUpdateEvent($user));
        $this->redirectByAction('invitation', 'redirectPasswordChanged');
        $uriBuilder = $this->getControllerContext()->getUriBuilder();
        $uri = $uriBuilder->reset()
            ->setAddQueryString(true)
            ->setAddQueryStringMethod('GET')
            ->uriFor('status');
        $this->redirectToUri($uri);
    }

    /**
     * Init for delete
     *
     * @return void
     */
    protected function initializeDeleteAction()
    {
    }

    /**
     * action delete
     *
     * @param int $user User UID
     * @param string $hash
     * @return void
     */
    public function deleteAction($user, $hash = null)
    {
        $user = $this->userRepository->findByUid($user);

        if ($user !== null && HashUtility::validHash($hash, $user)) {
            $this->logUtility->log(Log::STATUS_PROFILEDELETE, $user);
            $this->addFlashMessage(LocalizationUtility::translateByState(Log::STATUS_INVITATIONPROFILEDELETEDUSER));

            // send notify email to admin
            if ($this->settings['invitation']['notifyAdminStep1']) {
                $this->sendMailService->send(
                    'invitationRefused',
                    StringUtility::makeEmailArray(
                        $this->settings['invitation']['notifyAdminStep1'],
                        $this->settings['invitation']['email']['invitationRefused']['receiver']['name']['value']
                    ),
                    StringUtility::makeEmailArray($user->getEmail(), $user->getUsername()),
                    'Profile deleted from User after invitation - Step 1',
                    [
                        'user' => $user,
                        'settings' => $this->settings
                    ],
                    $this->config['invitation.']['email.']['invitationRefused.']
                );
            }

            $this->userRepository->remove($user);
            $this->redirectByAction('invitation', 'redirectDelete');
            $uriBuilder = $this->getControllerContext()->getUriBuilder();
            $uri = $uriBuilder->reset()
                ->setAddQueryString(true)
                ->setAddQueryStringMethod('GET')
                ->uriFor('status');
            $this->redirectToUri($uri);
        } else {
            $this->addFlashMessage(
                LocalizationUtility::translateByState(Log::STATUS_INVITATIONHASHERROR),
                '',
                FlashMessage::ERROR
            );
            $uriBuilder = $this->getControllerContext()->getUriBuilder();
            $uri = $uriBuilder->reset()
                ->setAddQueryString(true)
                ->setAddQueryStringMethod('GET')
                ->uriFor('status');
            $this->redirectToUri($uri);
        }
    }

    /**
     * Restricted Action to show messages
     *
     * @return void
     */
    public function statusAction()
    {
    }

    /**
     * Check if user is allowed to see this action
     *
     * @return bool
     */
    protected function allowedUserForInvitationNewAndCreate()
    {
        if (empty($this->settings['invitation']['allowedUserGroups'])) {
            return true;
        }
        $allowedUsergroupUids = GeneralUtility::trimExplode(
            ',',
            $this->settings['invitation']['allowedUserGroups'],
            true
        );
        $currentUsergroupUids = UserUtility::getCurrentUsergroupUids();

        // compare allowedUsergroups with currentUsergroups
        if (count(array_intersect($allowedUsergroupUids, $currentUsergroupUids))) {
            return true;
        }

        // current user is not allowed
        $this->addFlashMessage(
            LocalizationUtility::translateByState(Log::STATUS_INVITATIONRESTRICTEDPAGE),
            '',
            FlashMessage::ERROR
        );
        $this->forward('status');
        return false;
    }

    private function genderStringToInt(string $gender): ?int
    {
        $result = null;
        if ($gender === "Male") {
            $result = 0;
        } elseif ($gender === "Female") {
            $result = 1;
        }
        return $result;
    }

    private function genderIntToString(int $gender): string
    {
        $result = "NoInformation";
        if ($gender === 0) {
            $result = "Male";
        } elseif ($gender === 1) {
            $result = "Female";
        }
        return $result;
    }

    private function ensureInPortego(User $user): bool {
        $this->logger->warning("Trying to ensure prospect for "  . $user->getEmail());
        $salesProspectCreate = new SalesProspectCreate(
            $user->getFirstName(),
            $user->getLastName(),
            $user->getEmail(),
            $user->getTelephone(),
            $user->getTitle(),
            $user->getTitleSuffix(),
            $this->genderIntToString($user->getGender()),
            !empty($user->getDateOfBirth()) ? $user->getDateOfBirth()->format('Y-m-d') : '',
            $user->getNationality(),
            $user->getFamilyCount(),
            $user->getAddress(),
            $user->getZip(),
            $user->getCity(),
            $user->getCountry()
        );
        $responseEmptyResult = $this->salesProspectRepository->ensureProspect($salesProspectCreate);

        $this->logger->warning("Ensure Portego repsonse for user "  . $user->getEmail() . " is: " . $responseEmptyResult->getMessage());

        if (!empty($responseEmptyResult->getId())) {
            $user->setPortegoId($responseEmptyResult->getId());
            $this->logger->warning("Ensured prospect for user "  . $user->getEmail());
            return true;
        }

        return false;
    }
}
