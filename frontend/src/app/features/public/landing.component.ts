import { Component, computed, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { RouterLink } from '@angular/router';
import { TranslateModule } from '@ngx-translate/core';

import { AuthService } from '../../core/services/auth.service';
import { LanguageService } from '../../core/services/language.service';
import { PlatformLogoComponent } from '../../shared/platform-logo.component';

/** One feature of the system, as the landing page presents it. */
interface Feature {
  icon: string;
  titleKey: string;
  bodyKey: string;
}

/** One step of the story, numbered on the page. */
interface Step {
  icon: string;
  titleKey: string;
  bodyKey: string;
}

/**
 * The public front door.
 *
 * Everything behind it is a tool for somebody who already knows what this is; this
 * page is for the person who does not. It answers three questions in order — what
 * the system does, what it gives each person who touches it, and how a shop starts
 * using it — and every route out of it leads to signing in or registering.
 *
 * Deliberately outside the shell: no rail, no toolbar, no session. A visitor who has
 * never signed in must be able to read the whole thing.
 */
@Component({
  selector: 'app-landing',
  standalone: true,
  imports: [RouterLink, TranslateModule, MatButtonModule, MatIconModule, PlatformLogoComponent],
  templateUrl: './landing.component.html',
})
export class LandingComponent {
  private readonly auth = inject(AuthService);
  private readonly language = inject(LanguageService);

  readonly currentLanguage = this.language.current;

  /**
   * Whether somebody is already signed in.
   *
   * A visitor is offered a way in; a user who is already through the door is offered
   * their own screen instead, because "sign in" to somebody already signed in reads
   * as though the session were lost.
   */
  readonly isSignedIn = computed(() => this.auth.user() !== null);

  readonly homeRoute = computed(() => this.auth.homeRoute());

  /** What the system does, in the order a reader cares about it. */
  readonly features: Feature[] = [
    { icon: 'tune', titleKey: 'landing.featureRule', bodyKey: 'landing.featureRuleBody' },
    { icon: 'point_of_sale', titleKey: 'landing.featurePos', bodyKey: 'landing.featurePosBody' },
    { icon: 'redeem', titleKey: 'landing.featureReward', bodyKey: 'landing.featureRewardBody' },
    { icon: 'insights', titleKey: 'landing.featureReports', bodyKey: 'landing.featureReportsBody' },
    { icon: 'shield', titleKey: 'landing.featureGuard', bodyKey: 'landing.featureGuardBody' },
    { icon: 'smartphone', titleKey: 'landing.featureCustomer', bodyKey: 'landing.featureCustomerBody' },
  ];

  /** How a shop goes from reading this page to running the programme. */
  readonly steps: Step[] = [
    { icon: 'app_registration', titleKey: 'landing.step1', bodyKey: 'landing.step1Body' },
    { icon: 'verified', titleKey: 'landing.step2', bodyKey: 'landing.step2Body' },
    { icon: 'tune', titleKey: 'landing.step3', bodyKey: 'landing.step3Body' },
    { icon: 'storefront', titleKey: 'landing.step4', bodyKey: 'landing.step4Body' },
  ];

  toggleLanguage(): void {
    this.language.toggle();
  }
}
