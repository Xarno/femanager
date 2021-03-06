<?php
declare(strict_types=1);

namespace In2code\Femanager\Controller;

use In2code\Femanager\Domain\Model\Log;
use In2code\Femanager\Domain\Model\User;
use In2code\Femanager\Domain\Service\AutoAdminConfirmationService;
use In2code\Femanager\Utility\FrontendUtility;
use In2code\Femanager\Utility\HashUtility;
use In2code\Femanager\Utility\LocalizationUtility;
use In2code\Femanager\Utility\LogUtility;
use In2code\Femanager\Utility\StringUtility;
use In2code\Femanager\Utility\UserUtility;
use TYPO3\CMS\Core\Messaging\AbstractMessage;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Exception\UnsupportedRequestTypeException;
use TYPO3\CMS\Extbase\Persistence\Exception\IllegalObjectTypeException;

/**
 * Class NewController
 */
class NewController extends AbstractController
{

    /**
     * Render registration form
     *
     * @param User $user
     * @return void
     */
    public function newAction(User $user = null)
    {
        $this->view->assignMultiple(
            [
                'user' => $user,
                'allUserGroups' => $this->allUserGroups
            ]
        );
        $this->assignForAll();
    }

    /**
     * action create
     *
     * @param User $user
     * @validate $user In2code\Femanager\Domain\Validator\ServersideValidator
     * @validate $user In2code\Femanager\Domain\Validator\PasswordValidator
     * @validate $user In2code\Femanager\Domain\Validator\CaptchaValidator
     * @return void
     */
    public function createAction(User $user)
    {
        $user = UserUtility::overrideUserGroup($user, $this->settings);
        $user = FrontendUtility::forceValues($user, $this->config['new.']['forceValues.']['beforeAnyConfirmation.']);
        $user = UserUtility::fallbackUsernameAndPassword($user);
        $user = UserUtility::takeEmailAsUsername($user, $this->settings);
        UserUtility::hashPassword($user, $this->settings['new']['misc']['passwordSave']);
        $this->signalSlotDispatcher->dispatch(__CLASS__, __FUNCTION__ . 'BeforePersist', [$user, $this]);

        if ($this->isAllConfirmed()) {
            $this->createAllConfirmed($user);
        } else {
            $this->createRequest($user);
        }
    }

    /**
     * Dispatcher action for every confirmation request
     *
     * @param int $user User UID (user could be hidden)
     * @param string $hash Given hash
     * @param string $status
     *            "userConfirmation", "userConfirmationRefused", "adminConfirmation",
     *            "adminConfirmationRefused", "adminConfirmationRefusedSilent"
     * @return void
     */
    public function confirmCreateRequestAction($user, $hash, $status = 'adminConfirmation')
    {
        $user = $this->userRepository->findByUid($user);
        $this->signalSlotDispatcher->dispatch(
            __CLASS__,
            __FUNCTION__ . 'BeforePersist',
            [$user, $hash, $status, $this]
        );
        if ($user === null) {
            $this->addFlashMessage(LocalizationUtility::translate('missingUserInDatabase'), '', FlashMessage::ERROR);
            $this->redirect('new');
        }

        switch ($status) {
            case 'userConfirmation':
                $furtherFunctions = $this->statusUserConfirmation($user, $hash, $status);
                break;

            case 'userConfirmationRefused':
                $furtherFunctions = $this->statusUserConfirmationRefused($user, $hash);
                break;

            case 'adminConfirmation':
                $furtherFunctions = $this->statusAdminConfirmation($user, $hash, $status);
                break;

            case 'adminConfirmationRefused':
                // Admin refuses profile
            case 'adminConfirmationRefusedSilent':
                $furtherFunctions = $this->statusAdminConfirmationRefused($user, $hash, $status);
                break;

            default:
                $furtherFunctions = false;

        }

        if ($furtherFunctions) {
            $this->redirectByAction('new', $status . 'Redirect');
        }
        $this->redirect('new');
    }

    /**
     * Status action: User confirmation
     *
     * @param User $user
     * @param string $hash
     * @param string $status
     * @return bool allow further functions
     * @throws UnsupportedRequestTypeException
     * @throws IllegalObjectTypeException
     */
    protected function statusUserConfirmation(User $user, $hash, $status)
    {
        if (HashUtility::validHash($hash, $user)) {
            if ($user->getTxFemanagerConfirmedbyuser()) {
                $this->addFlashMessage(LocalizationUtility::translate('userAlreadyConfirmed'), '', FlashMessage::ERROR);
                $this->redirect('new');
            }

            $user = FrontendUtility::forceValues($user, $this->config['new.']['forceValues.']['onUserConfirmation.']);
            $user->setTxFemanagerConfirmedbyuser(true);
            $this->userRepository->update($user);
            $this->persistenceManager->persistAll();
            LogUtility::log(Log::STATUS_REGISTRATIONCONFIRMEDUSER, $user);

            if ($this->isAdminConfirmationMissing($user)) {
                $this->createAdminConfirmationRequest($user);
            } else {
                $user->setDisable(false);
                LogUtility::log(Log::STATUS_NEWREGISTRATION, $user);
                $this->finalCreate($user, 'new', 'createStatus', true, $status);
            }

        } else {
            $this->addFlashMessage(LocalizationUtility::translate('createFailedProfile'), '', FlashMessage::ERROR);

            return false;
        }

        return true;
    }

    /**
     * Status action: User confirmation refused
     *
     * @param User $user
     * @param string $hash
     * @return bool allow further functions
     * @throws IllegalObjectTypeException
     */
    protected function statusUserConfirmationRefused(User $user, $hash)
    {
        if (HashUtility::validHash($hash, $user)) {
            LogUtility::log(Log::STATUS_REGISTRATIONREFUSEDUSER, $user);
            $this->addFlashMessage(LocalizationUtility::translate('createProfileDeleted'));
            $this->userRepository->remove($user);
        } else {
            $this->addFlashMessage(LocalizationUtility::translate('createFailedProfile'), '', FlashMessage::ERROR);

            return false;
        }

        return true;
    }

    /**
     * Status action: Admin confirmation
     *
     * @param User $user
     * @param string $hash
     * @param string $status
     * @return bool allow further functions
     */
    protected function statusAdminConfirmation(User $user, $hash, $status)
    {
        if (HashUtility::validHash($hash, $user)) {
            $user = FrontendUtility::forceValues($user, $this->config['new.']['forceValues.']['onAdminConfirmation.']);
            $user->setTxFemanagerConfirmedbyadmin(true);
            $user->setDisable(false);
            $this->userRepository->update($user);
            LogUtility::log(Log::STATUS_REGISTRATIONCONFIRMEDADMIN, $user);
            $this->finalCreate($user, 'new', 'createStatus', false, $status);
        } else {
            $this->addFlashMessage(LocalizationUtility::translate('createFailedProfile'), '', FlashMessage::ERROR);

            return false;
        }

        return true;
    }

    /**
     * Status action: Admin refused profile creation (normal or silent)
     *
     * @param User $user
     * @param $hash
     * @param $status
     * @return bool allow further functions
     * @throws IllegalObjectTypeException
     */
    protected function statusAdminConfirmationRefused(User $user, $hash, $status)
    {
        if (HashUtility::validHash($hash, $user)) {
            LogUtility::log(Log::STATUS_REGISTRATIONREFUSEDADMIN, $user);
            $this->addFlashMessage(LocalizationUtility::translate('createProfileDeleted'));
            if ($status !== 'adminConfirmationRefusedSilent') {
                $this->sendMailService->send(
                    'CreateUserNotifyRefused',
                    StringUtility::makeEmailArray(
                        $user->getEmail(),
                        $user->getFirstName() . ' ' . $user->getLastName()
                    ),
                    ['sender@femanager.org' => 'Sender Name'],
                    'Your profile was refused',
                    ['user' => $user],
                    $this->config['new.']['email.']['createUserNotifyRefused.']
                );
            }
            $this->userRepository->remove($user);
        } else {
            $this->addFlashMessage(LocalizationUtility::translate('createFailedProfile'), '', FlashMessage::ERROR);

            return false;
        }

        return true;
    }

    /**
     * Just for showing informations after user creation
     *
     * @return void
     */
    public function createStatusAction()
    {
    }

    /**
     * Postfix method to createAction(): Create must be confirmed by Admin or User
     *
     * @param User $user
     * @return void
     */
    protected function createRequest(User $user)
    {
        $user->setDisable(true);
        $this->userRepository->add($user);
        $this->persistenceManager->persistAll();
        LogUtility::log(Log::STATUS_PROFILECREATIONREQUEST, $user);
        if (!empty($this->settings['new']['confirmByUser'])) {
            $this->createUserConfirmationRequest($user);
            $this->redirect('new');
        }
        if (!empty($this->settings['new']['confirmByAdmin'])) {
            $this->createAdminConfirmationRequest($user);
            $this->redirect('new');
        }
    }

    /**
     * Send email to user for confirmation
     *
     * @param User $user
     * @return void
     * @throws UnsupportedRequestTypeException
     */
    protected function createUserConfirmationRequest(User $user)
    {
        $this->sendCreateUserConfirmationMail($user);
        $this->redirectByAction('new', 'requestRedirect');
        $this->addFlashMessage(LocalizationUtility::translate('createRequestWaitingForUserConfirm'));
    }

    /**
     * Send email to admin for confirmation
     *
     * @param User $user
     * @throws UnsupportedRequestTypeException
     */
    protected function createAdminConfirmationRequest(User $user)
    {
        $aacService = $this->objectManager->get(
            AutoAdminConfirmationService::class,
            $user,
            $this->settings,
            $this->contentObject
        );
        if ($aacService->isAutoAdminConfirmationFullfilled()) {
            $user->setDisable(false);
            $this->signalSlotDispatcher->dispatch(__CLASS__, __FUNCTION__ . 'AutoConfirmation', [$user, $this]);
            $this->createAllConfirmed($user);
        } else {
            $this->signalSlotDispatcher->dispatch(__CLASS__, __FUNCTION__ . 'ManualConfirmation', [$user, $this]);
            $this->sendMailService->send(
                'createAdminConfirmation',
                StringUtility::makeEmailArray(
                    $this->settings['new']['confirmByAdmin'],
                    $this->settings['new']['email']['createAdminConfirmation']['receiver']['name']['value']
                ),
                StringUtility::makeEmailArray($user->getEmail(), $user->getUsername()),
                'New Registration request',
                [
                    'user' => $user,
                    'hash' => HashUtility::createHashForUser($user)
                ],
                $this->config['new.']['email.']['createAdminConfirmation.']
            );
            $this->addFlashMessage(LocalizationUtility::translate('createRequestWaitingForAdminConfirm'));
        }
    }

    /**
     * @return bool
     */
    protected function isAllConfirmed()
    {
        return empty($this->settings['new']['confirmByUser']) && empty($this->settings['new']['confirmByAdmin']);
    }

    /**
     * @param User $user
     * @return bool
     */
    protected function isAdminConfirmationMissing(User $user)
    {
        return !empty($this->settings['new']['confirmByAdmin']) && !$user->getTxFemanagerConfirmedbyadmin();
    }

    /**
     * Just for showing empty dialogue to resend confirmation mail
     *
     * @return void
     */
    public function resendConfirmationDialogueAction()
    {

    }

    /**
     * re-sends a confirmation email if given mail is valid
     *
     * @return void
     * @throws UnsupportedRequestTypeException
     * @throws \TYPO3\CMS\Extbase\Mvc\Exception\StopActionException
     */
    public function resendConfirmationMailAction()
    {
        // @todo find a better way to fetch the data
        $result = GeneralUtility::_GP('tx_femanager_pi1');
        if (is_array($result)) {
            if (GeneralUtility::validEmail($result['user']['email'])) {
                $user = $this->userRepository->findFirstByEmail($result['user']['email']);
                if (is_a($user, User::class)) {
                    $this->sendCreateUserConfirmationMail($user);
                    $this->addFlashMessage(LocalizationUtility::translate('resendConfirmationMailSend'),
                        '', AbstractMessage::INFO);
                    $this->redirect('resendConfirmationDialogue');
                }
            }
        }
        $this->addFlashMessage(LocalizationUtility::translate('resendConfirmationMailFail')
            , LocalizationUtility::translate('validationError'), AbstractMessage::ERROR);
        $this->redirect('resendConfirmationDialogue');
    }

    /**
     * Empty Dialog. Access with:  https://<mydomain>/<mypage>/unsubscribe/?key=12345
     * The get Parameter key is required for unsubscribe to work.
     * Key is composed of these 2 Direct Mail tokens: ###USER_uid######SYS_AUTHCODE###
     * @return void
     */
    public function newsletterUnsubscribeDialogueAction()
    {
      // htmlspecialchars gegen XSS
      $key = htmlspecialchars(GeneralUtility::_GP('key'));
      $this->view->assign('key', $key);
    }
    /**
     * Requires tx_femanager_pi1[directMailKey]=123… as post parameter
     * @return void
     * @throws UnsupportedRequestTypeException
     * @throws \TYPO3\CMS\Extbase\Mvc\Exception\StopActionException
     */
    public function unsubscribeAction()
    {
        // @todo find a better way to fetch the data
        $result = GeneralUtility::_GP('tx_femanager_pi1');
        if (is_array($result)) {
            $checker = new Checker($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'], $result['directMailKey']);
            $uid = $checker->getValidUserId();
            if (GeneralUtility::validEmail($result['user']['email']) && $uid > -1) {
                $user = $this->userRepository->findFirstByUidAndEmail($uid, $result['user']['email']);

                if (is_a($user, User::class)) {
                    $user->setModuleSysDmailNewsletter(0);
                    $this->userRepository->update($user);
                    $this->addFlashMessage(LocalizationUtility::translate('newsletterUnsubscribed', 'cs_fe_manager_extension'),
                        '', AbstractMessage::INFO);
                    $this->redirect('newsletterUnsubscribeDialogue');
                }
            }
        }
        $this->addFlashMessage(LocalizationUtility::translate('newsletterUnsubscribeFail', 'cs_fe_manager_extension')
            , LocalizationUtility::translate('validationError'), AbstractMessage::ERROR);
        $this->redirect('newsletterUnsubscribeDialogue');
    }
}

// Mimics the DirectMail Token handling. And validates the key.
class Checker {

    private $encryptionKey;
    private $code;
    private $uid;
    private $hash;


    function __construct($encryptionKey, $code) {
        $this->encryptionKey = $encryptionKey;
        $this->code = $code;
    }

    public function getValidUserId() {
        if ($this->checkParams() && $this->checkHash()) {
            return $this->uid;
        }
        return -1;
    }

    private function checkParams() {
        if (!isset($this->code) || strlen($this->code <= 8)) {
            echo 'isset';
            return false;
        }
        $this->uid = substr($this->code, 0, strlen($this->code) - 8);
        $this->hash = substr($this->code, strlen($this->uid));
        if(!is_numeric($this->uid)) {
            echo 'is_numeric';
           return false;
        }
        return true;
    }

    private function checkHash() {
        $md5Test = substr(md5($this->uid . '||' . $this->encryptionKey), 0, 8);
        return $md5Test === $this->hash;
    }

}
