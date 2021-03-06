freedomApp.controller('MainController', function($scope, $rootScope, $http, $location, $window, facebook, safeApply) {
  'use strict';

  var userId;
  var saveAccessToken = function(){
    $http({
      method: 'POST',
      url: '/users/save-access-token'
    }).success(function(response) {

      userId = FB.getAuthResponse().userID;
      var secureHash = response.secure_hash;

      // Add facebook login event to GA
      $window.ga('send', 'event', 'facebookLogin', 'success', userId, 5);

      // to avoid Google Calendar caching an old feed
      var dummy = Math.floor(Math.random() * 1000);

      // update DOM
      $scope.downloadFeedHref = 'webcal://freedom.konscript.com/feed.ics?user_id=' + userId + '&secure_hash=' + secureHash + '&dummy=' + dummy;
      $scope.googleButtonHref = 'http://www.google.com/calendar/render?cid=' + encodeURIComponent($scope.downloadFeedHref);

      // next step
      $scope.step = 2;
      $scope.isLoading = false;
    }).error(function(){
      $scope.errorMessage = 'Ouch! We are sorry, but we are having problems setting up your calendar. Try again later.';
      $scope.isLoading = false;
    });
  };

  var facebookLogin = function(){
    var facebookPermissions = 'user_events, user_groups'; // user_friends, read_friendlists

    FB.login(function(response) {
      if(response.authResponse){
        saveAccessToken(); // Successfully logged in
      }else{
        onFacebookConnectDeclinedByUser(); // User aborted Facebook login
      }
    }, {scope: facebookPermissions});
  };

  var onFacebookConnectDeclinedByUser = function(){
    $window.ga('send', 'event', 'facebookLogin', 'failed', null, 0);

    $scope.$apply(function() {
      $scope.errorMessage = 'It seems like you did not login with Facebook. Please try again.';
      $scope.isLoading = false;
    });
  };

  // Set user information to help answer bug reports
  var setUserVoiceIdentity = function(){
    FB.api('/me', function(user){
      var name = user.first_name + ' ' + user.last_name;

      $window.UserVoice.push(['identify', {
        name: name,
        id: user.id
      }]);

    });
  };


  // Default values
  $scope.step = 1;
  $scope.errorMessage = '';
  $scope.isLoading = false;
  $scope.loggedIn = function(){
    return facebook.isLoggedIn;
  };

  // If user is logged in
  facebook.loggedInReady.then(function(auth){
    setUserVoiceIdentity();
  });

  // Listen for route changes
  $scope.$on('$routeChangeSuccess', function(next, current) {
    // Set current path
    $scope.currentPath = $location.path().substring(1);

    // Track analytics on route change
    $window.ga('send', 'pageview', $location.path());

  });

  $scope.facebookLogin = facebookLogin;

  $scope.isAndroid = function() {
    var ua = navigator.userAgent.toLowerCase();
    return ua.indexOf('android') > -1; //&& ua.indexOf('mobile');
  };

  $scope.connectWithFacebook = function() {
    // remove all alerts
    $scope.errorMessage = '';
    $scope.isLoading = true;

    // get token with access to user_events and user_groups
    facebook.sdkReady.then(function(auth){
      // User not logged in
      if(auth !== 'connected'){
        facebookLogin();

      // User already logged in
      }else{
        saveAccessToken();
      }

    // Facebook SDK could not be loaded
    },function(response){
      $window.ga('send', 'event', 'facebookLogin', 'timeout');
      $scope.errorMessage = 'A connection to Facebook could not be established. If you have installed any blocking extensions like Ghostery, Do Not Track Me, Priv3 or anything similar, you must disable them, or whitelist this website.';
      $scope.isLoading = false;
    });
  }; // End of connectWithFacebook function

  $scope.addToCalendarGoogle = function() {
    $scope.step = 3;
    $window.ga('send', 'event', 'addToCalendar', 'google', userId, 10);
  };

  $scope.addToCalendarDownload = function() {
    $scope.step = 3;
    $window.ga('send', 'event', 'addToCalendar', 'download', userId, 10);
  };

  $scope.isActive = function(path) {
    return $scope.currentPath === path;
  };

});
