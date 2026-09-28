/* ExamLegacy — authentication & session.
   Firebase Google Sign-In. The ID token is attached to API calls; the server
   verifies it and maps to a MySQL user. Client state is never authoritative. */
window.EL = window.EL || {};

EL.auth = (function () {
  var firebaseUser = null;
  var profile = null;       // MySQL user profile from /me
  var listeners = [];
  var ready = false;

  function init() {
    if (typeof firebase === 'undefined') {
      return Promise.reject(new Error('Firebase SDK not loaded'));
    }
    if (!firebase.apps || !firebase.apps.length) {
      firebase.initializeApp(EL.FIREBASE_CONFIG);
    }
    return new Promise(function (resolve) {
      firebase.auth().onAuthStateChanged(function (user) {
        firebaseUser = user || null;
        ready = true;
        if (user) {
          // Sync/refresh the server profile.
          EL.api.get('/me').then(function (p) { profile = p.user; }).catch(function () { profile = null; })
            .finally(function () { emit(); resolve(user); });
        } else {
          profile = null;
          emit();
          resolve(null);
        }
      });
    });
  }

  function emit() { listeners.forEach(function (cb) { try { cb(firebaseUser, profile); } catch (e) {} }); }

  return {
    init: init,
    onChange: function (cb) { listeners.push(cb); },
    isReady: function () { return ready; },
    user: function () { return firebaseUser; },
    profile: function () { return profile; },
    refreshProfile: function () {
      return EL.api.get('/me').then(function (p) { profile = p.user; emit(); return profile; });
    },
    currentToken: async function () {
      if (!firebaseUser) return null;
      try { return await firebaseUser.getIdToken(/* forceRefresh */ false); }
      catch (e) { return null; }
    },
    signInWithGoogle: async function () {
      var provider = new firebase.auth.GoogleAuthProvider();
      provider.setCustomParameters({ prompt: 'select_account' });
      var result = await firebase.auth().signInWithPopup(provider);
      firebaseUser = result.user;
      profile = await EL.api.get('/me').then(function (p) { return p.user; });
      emit();
      return profile;
    },
    signOut: async function () {
      await firebase.auth().signOut();
      firebaseUser = null; profile = null; emit();
    }
  };
})();
